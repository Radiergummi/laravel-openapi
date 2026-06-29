<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Composer\InstalledVersions;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\Visitors\RouteRule;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function fnmatch;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function max;
use function property_exists;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function str_contains;

/**
 * Extracted from {@see LintCommand} so the pipeline is testable. Two phases: pre-build
 * ({@see Visitors\PreBuildRule}) and per-spec (generate + tree-walk, findings tagged via
 * {@see Finding::withSpec()}).
 */
#[Scoped]
final readonly class LintRunner
{
    /**
     * @param null|list<string> $enabledRules          `openapi.lint.enabled_rules`; null = unset (distinct from `[]` = all disabled).
     * @param list<string>      $disabledRules         `openapi.lint.disabled_rules`.
     * @param int|string        $configuredLevel       `openapi.lint.level`; numeric or `"max"`.
     * @param ?float            $configuredMinCoverage `openapi.lint.min_coverage`; null when unset.
     * @param ?int              $configuredMaxFindings `openapi.lint.max_findings`; null when unset.
     */
    public function __construct(
        private Container $container,
        private RouteIntrospector $introspector,
        private RuleRegistry $registry,
        private SuppressionCollector $suppressionCollector,
        private OpenApiRegistry $openApiRegistry,
        private LintRouteFilter $routeFilter,
        private SpecRegistry $specRegistry,
        private OpenApiGenerationOrchestrator $orchestrator,
        private InclusionEvaluator $evaluator,
        private Dispatcher $events,
        private LoggerInterface $logger,
        #[Config('openapi.lint.enabled_rules')]
        private ?array $enabledRules = null,
        #[Config('openapi.lint.disabled_rules', [])]
        private array $disabledRules = [],
        #[Config('openapi.lint.level', 0)]
        private int|string $configuredLevel = 0,
        #[Config('openapi.lint.min_coverage')]
        private ?float $configuredMinCoverage = null,
        #[Config('openapi.lint.max_findings')]
        private ?int $configuredMaxFindings = null,
    ) {}

    /**
     * @throws \LogicException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ProcessRuntimeException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function run(LintOptions $options): LintResult
    {
        $inner = new ArrayFindingsCollector();
        $emit = new EventDispatchingFindingsCollector($inner, $this->events, $this->logger);
        $this->container->instance(FindingsCollector::class, $emit);

        try {
            return $this->runWithCollector($options, $inner, $emit);
        } finally {
            $this->container->forgetInstance(FindingsCollector::class);
        }
    }

    /**
     * @throws \LogicException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ProcessRuntimeException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function runWithCollector(
        LintOptions $options,
        ArrayFindingsCollector $inner,
        FindingsCollector $emit,
    ): LintResult {
        // region Descriptor collection + path/diff filtering

        $globalFiltered = $this->collectGlobalFiltered();

        // Tree-walk scope mirrors the generated document, which never emits an operation for a
        // missing-method route. The per-route lint pass needs those routes, so it runs over a
        // superset that keeps them; re-admitting them to the tree walk would draw spurious findings.
        $descriptors = $this->routeFilter->filter(
            descriptors: $globalFiltered,
            uriGlob: $options->uriGlob,
            files: $options->files,
            diff: $options->diff,
        );
        $routeRuleDescriptors = $this->routeFilter->filterForRouteRules(
            descriptors: $globalFiltered,
            uriGlob: $options->uriGlob,
            files: $options->files,
            diff: $options->diff,
        );

        // Stage-emitted findings bypass the tree-walk path restriction, so they need a URI gate.
        $allowedRouteUris = null;

        // Schema findings carry no routeUri; scope them by reachability instead.
        // $allComponentClasses is the confidence gate: unknown = route-agnostic = keep.
        $allowedSchemaClasses = [];
        $allComponentClasses = [];

        // Tree-walk path scope: the dead-routes-dropped set, so tree-walk rules never see an
        // operation the generator emitted for a missing-method route. Null when unscoped.
        $treeWalkUris = null;

        if ($options->isScoped) {
            $treeWalkUris = self::descriptorUriSet($descriptors);

            // The route-rule set is a superset that keeps missing-method routes. Gating findings by
            // its URIs lets per-route findings on those routes survive; their operations are still
            // excluded from the tree walk via $treeWalkUris, so no tree-walk finding leaks through.
            $allowedRouteUris = self::descriptorUriSet($routeRuleDescriptors);
        }

        // endregion

        // region Option resolution

        $only = $this->resolveOnly($options->only);
        $skip = $this->resolveSkip($options->skip);
        $level = $this->resolveLevel($options);

        // --only: surface targeted rules at any severity.
        if ($only !== []) {
            $level = max($level, $this->registry->maxLevel());
        }

        $rules = $this->registry->forLevel($level, only: $only, skip: $skip);

        if (!$options->validateSpec) {
            $rules = array_values(
                array_filter(
                    $rules,
                    static fn(Rule $rule): bool => $rule->id !== RuleRegistry::EXEMPT_RULE_ID,
                ),
            );
        }

        // Empty when no active rule needs inference; skips control generation entirely.
        $inferenceExcludedStages = $this->inferenceExcludedStages($rules);

        // endregion

        // region Pre-build phase: runs once, findings carry spec: null

        foreach ($this->registry->preBuildRules() as $preBuildRule) {
            $preBuildRule->checkConfiguration($this->specRegistry, $descriptors, $emit);
        }

        // endregion

        // region Per-spec phase

        $targets = $options->spec !== null
            ? [$this->specRegistry->get($options->spec)]
            : $this->specRegistry->all();

        $descriptorDirectives = $this->suppressionCollector->collect($descriptors);
        $suppressionsAll = $descriptorDirectives;

        // Accumulated across every target spec; feeds the coverage calculator.
        // Built from the same in-scope tree the walk uses, so it honours --path/--diff.
        /** @var array<string, list<string>> $operationTags */
        $operationTags = [];

        // Source locations for line-keyed coverage reports (Cobertura/LCOV). Null file/line for closure routes.
        /** @var array<string, array{file: ?string, line: ?int}> $operationLocations */
        $operationLocations = [];

        foreach ($targets as $spec) {
            // Spec-local collector survives forgetScopedInstances(); drained via $emit below.
            $specLocal = new ArrayFindingsCollector();
            $this->container->forgetInstance(FindingsCollector::class);
            $this->container->instance(FindingsCollector::class, $specLocal);

            $document = $this->orchestrator->generateOne($spec->name);

            // Re-resolve so the class map reflects schemas registered during this run.
            $liveRegistry = $this->container->make(ComponentSchemaRegistry::class);
            $classMap = $liveRegistry->componentClassMap();

            if ($allowedRouteUris !== null) {
                foreach ($classMap as $componentClass) {
                    $allComponentClasses[$componentClass] = true;
                }

                foreach (
                    array_keys(
                        $this->reachableComponentClasses($document, $allowedRouteUris, $classMap),
                    ) as $class
                ) {
                    $allowedSchemaClasses[$class] = true;
                }
            }

            $componentDirectives = $this->suppressionCollector->collectFromComponentSchemas($classMap);
            $specSuppressions = [...$descriptorDirectives, ...$componentDirectives];
            $suppressionsAll = [...$suppressionsAll, ...$componentDirectives];

            // Build inference control document before the walk; skipped when no rule needs it.
            $inference = $inferenceExcludedStages !== []
                ? InferenceView::from($this->orchestrator->inferenceOnly($spec->name, $inferenceExcludedStages))
                : new InferenceView();

            $operations = $this->walkSpec(
                $document,
                $descriptors,
                $routeRuleDescriptors,
                $treeWalkUris,
                $rules,
                $specSuppressions,
                $specLocal,
                $level,
                $only,
                $skip,
                componentClassMap: $classMap,
                inference: $inference,
            );

            [$specTags, $specLocations] = $this->collectOperationCoverage($operations, $allowedRouteUris, $spec->name);
            $operationTags = [...$operationTags, ...$specTags];
            $operationLocations = [...$operationLocations, ...$specLocations];

            foreach ($specLocal->all() as $finding) {
                $emit->emit($finding->withSpec($spec->name));
            }
        }

        // endregion

        // region Post-processing: overrides, --only/--skip, suppressions, level filter

        $findings = $this->filterFindings(
            $inner->all(),
            $options,
            $level,
            $only,
            $skip,
            $suppressionsAll,
            $allowedRouteUris,
            $allowedSchemaClasses,
            $allComponentClasses,
        );

        // endregion

        $coverage = new CoverageCalculator()->calculate(
            $operationTags,
            $findings,
            $level,
            $this->generatorVersion(),
            $operationLocations,
        );

        return new LintResult(
            $findings,
            $level,
            $this->resolveExitCode($findings, $coverage, $options),
            $coverage,
        );
    }

    /**
     * Discovers routes and drops global-filter rejects, before --path/--files/--diff narrowing.
     *
     * @return list<ActionDescriptor>
     *
     * @throws ReflectionException
     * @throws UnexpectedValueException
     */
    private function collectGlobalFiltered(): array
    {
        $descriptors = [];

        foreach ($this->introspector->discover() as $descriptor) {
            if (!$this->evaluator->passesGlobalFilters($descriptor)) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * URI set (leading slash trimmed) shared by the finding filter, tree-walk, and schema reachability.
     *
     * @param list<ActionDescriptor> $descriptors
     *
     * @return array<string, true>
     */
    private static function descriptorUriSet(array $descriptors): array
    {
        $uris = [];

        foreach ($descriptors as $descriptor) {
            $uris[ltrim($descriptor->route->uri(), '/')] = true;
        }

        return $uris;
    }

    /**
     * Resolves the effective --only list, merging CLI with `openapi.lint.enabled_rules`.
     * Both set: intersection. Only one set: use it. Neither: empty (no restriction).
     *
     * @param list<string> $cli
     *
     * @return list<string>
     */
    private function resolveOnly(array $cli): array
    {
        $cli = $this->expandRulePatterns($cli);

        if ($this->enabledRules === null) {
            return $cli;
        }

        $enabled = $this->expandRulePatterns($this->enabledRules);

        return $cli !== []
            ? array_values(
                array_filter(
                    $cli,
                    static fn(string $id): bool => in_array($id, $enabled, true),
                ),
            )
            : $enabled;
    }

    /**
     * Expands `*`-glob patterns against registered rule IDs (e.g. `migration.*`). Non-glob entries
     * pass through verbatim. A glob matching nothing is kept literally so it still constrains.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function expandRulePatterns(array $patterns): array
    {
        if ($patterns === []) {
            return [];
        }

        $known = $this->registry->knownIds();
        $expanded = [];

        foreach ($patterns as $pattern) {
            if (!str_contains($pattern, '*')) {
                $expanded[] = $pattern;

                continue;
            }

            $matches = array_values(
                array_filter(
                    $known,
                    static fn(string $id): bool => fnmatch($pattern, $id),
                ),
            );

            // Keep the literal pattern when nothing matches so it still constrains to "no rules".
            $expanded = [...$expanded, ...($matches !== [] ? $matches : [$pattern])];
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Resolves the effective --skip list, merging CLI with `openapi.lint.disabled_rules`.
     * `spec.invalid` is unconditionally excluded; it cannot be disabled.
     *
     * @param list<string> $cli
     *
     * @return list<string>
     */
    private function resolveSkip(array $cli): array
    {
        $merged = array_values(
            array_unique(
                array_merge(
                    $this->expandRulePatterns($cli),
                    $this->expandRulePatterns($this->disabledRules),
                ),
            ),
        );

        return array_values(
            array_filter(
                $merged,
                static fn(string $id): bool => $id !== RuleRegistry::EXEMPT_RULE_ID,
            ),
        );
    }

    private function resolveLevel(LintOptions $options): int
    {
        $raw = $options->level ?? $this->configuredLevel;

        return $raw === 'max'
            ? $this->registry->maxLevel()
            : max(0, (int) $raw);
    }

    /**
     * Stages to exclude when building the inference-only control document. Empty when no active
     * rule declares inference comparison, skipping control generation entirely.
     *
     * @param list<Rule> $rules
     *
     * @return list<class-string<SpecStage>>
     */
    private function inferenceExcludedStages(array $rules): array
    {
        $stages = [];

        foreach ($rules as $rule) {
            if ($rule instanceof NeedsInferenceDocument) {
                $stages = [...$stages, ...$rule->excludedStages()];
            }
        }

        return array_values(array_unique($stages));
    }

    /**
     * Component classes transitively reachable from in-scope operations via `$ref` chains.
     *
     * @param array<string, true>         $allowedRouteUris  in-scope route URIs, leading slash trimmed
     * @param array<string, class-string> $componentClassMap component schema name → class
     *
     * @return array<class-string, true>
     */
    private function reachableComponentClasses(
        OA\OpenApi $document,
        array $allowedRouteUris,
        array $componentClassMap,
    ): array {
        $componentsByName = [];

        if ($document->components !== null && is_array($document->components->schemas)) {
            foreach ($document->components->schemas as $schema) {
                if (is_string($schema->schema)) {
                    $componentsByName[$schema->schema] = $schema;
                }
            }
        }

        $reachable = [];
        $queue = [];

        foreach (self::inScopePathItems($document, $allowedRouteUris) as $pathItem) {
            foreach (self::refSchemaNames($pathItem) as $name) {
                if (!isset($reachable[$name])) {
                    $reachable[$name] = true;
                    $queue[] = $name;
                }
            }
        }

        while ($queue !== []) {
            $component = $componentsByName[array_pop($queue)] ?? null;

            if ($component === null) {
                continue;
            }

            foreach (self::refSchemaNames($component) as $next) {
                if (!isset($reachable[$next])) {
                    $reachable[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        $classes = [];

        foreach (array_keys($reachable) as $name) {
            $class = $componentClassMap[$name] ?? null;

            if ($class !== null) {
                $classes[$class] = true;
            }
        }

        return $classes;
    }

    /**
     * @param array<string, true> $allowedRouteUris
     *
     * @return list<OA\PathItem>
     */
    private static function inScopePathItems(OA\OpenApi $document, array $allowedRouteUris): array
    {
        if (!is_array($document->paths)) {
            return [];
        }

        return array_values(
            array_filter(
                $document->paths,
                static fn(OA\PathItem $p): bool
                    => is_defined($p->path)
                    && is_string($p->path)
                    && isset($allowedRouteUris[ltrim($p->path, '/')]),
            ),
        );
    }

    /**
     * Component names referenced via `$ref` anywhere in the annotation subtree.
     *
     * @return list<string>
     */
    private static function refSchemaNames(OA\AbstractAnnotation $root): array
    {
        $names = [];

        AnnotationWalker::walk($root, static function (OA\AbstractAnnotation $annotation) use (&$names): void {
            if (!property_exists($annotation, 'ref')) {
                return;
            }

            $ref = $annotation->ref;

            if (is_undefined($ref) || !is_string($ref)) {
                return;
            }

            $name = ComponentReference::name($ref);

            if ($name !== null) {
                $names[] = $name;
            }
        });

        return $names;
    }

    /**
     * Runs the tree walk, RouteRule pass, and MetaSuppressionStale for one spec.
     *
     * The RouteRule pass runs over `$routeRuleDescriptors`, which (unlike the tree-walk
     * `$descriptors`) keeps first-party missing-method routes so they can be flagged without
     * re-entering the tree-walk scope and drawing spurious operation/response findings.
     *
     * @param list<Rule>                  $rules
     * @param list<ActionDescriptor>      $descriptors          Tree-walk scope (mirrors the document).
     * @param list<ActionDescriptor>      $routeRuleDescriptors Per-route pass scope (superset).
     * @param null|array<string, true>    $treeWalkUris         In-scope tree-walk URIs; null = unscoped.
     * @param list<SuppressionDirective>  $suppressions
     * @param list<string>                $only
     * @param list<string>                $skip
     * @param array<string, class-string> $componentClassMap
     *
     * @return list<OperationNode> the in-scope operations walked
     *
     * @throws \LogicException
     */
    private function walkSpec(
        OA\OpenApi $document,
        array $descriptors,
        array $routeRuleDescriptors,
        ?array $treeWalkUris,
        array $rules,
        array $suppressions,
        ArrayFindingsCollector $specLocal,
        int $level,
        array $only,
        array $skip,
        array $componentClassMap = [],
        InferenceView $inference = new InferenceView(),
    ): array {
        // region Tree walk

        $staleChecker = new MetaSuppressionStale();

        $knownRuleIds = [
            ...$this->registry->knownIds(),
            MetaSuppressionStale::ID,
        ];

        // Restrict to the allowed set so rules don't fire on routes excluded by --uri/--path/--diff,
        // including missing-method routes: the per-route pass handles those, but the tree walk must
        // not see the operations the generator still emits for them. A scoped run narrows even to an
        // empty set (nothing left to walk); an unscoped run narrows to its discovered routes.
        if (is_array($document->paths)) {
            if ($treeWalkUris !== null) {
                $document->paths = self::inScopePathItems($document, $treeWalkUris);
            } elseif ($descriptors !== []) {
                $document->paths = self::inScopePathItems($document, self::descriptorUriSet($descriptors));
            }
        }

        $treeBuilder = new SpecTreeBuilder(
            componentClassMap: $componentClassMap,
        );
        $api = $treeBuilder->build($document, $descriptors);

        $registeredScopes = class_exists(Passport::class)
            ? array_keys(Passport::scopes()->keyBy('id')->all())
            : [];

        $index = TreeIndex::build(
            $api,
            $document,
            $knownRuleIds,
            $registeredScopes,
        );

        $context = new LintContext(
            api: $api,
            index: $index,
            rawSpec: $document,
            actionDescriptors: $descriptors,
            suppressions: $suppressions,
            payloadClasses: $this->openApiRegistry->payloadClasses,
            inference: $inference,
        );

        $walker = new SpecTreeWalker($rules);

        foreach ($walker->walk($api, $context) as $finding) {
            $specLocal->emit($finding);
        }

        // endregion

        // region RouteRule pass

        $routeRules = array_values(
            array_filter(
                $rules,
                static fn(Rule $rule): bool => $rule instanceof RouteRule,
            ),
        );

        if ($routeRules !== []) {
            foreach ($routeRuleDescriptors as $descriptor) {
                $defaults = FindingLocation::fromDescriptor($descriptor);

                foreach ($routeRules as $rule) {
                    foreach ($rule->checkRoute($descriptor, $context) as $finding) {
                        $specLocal->emit($finding->withLocationDefaults($defaults));
                    }
                }
            }
        }

        // endregion

        // region MetaSuppressionStale (post-walk)

        if ($this->metaRuleEnabled($staleChecker, $level, $only, $skip)) {
            foreach ($staleChecker->check($context, $specLocal->all()) as $finding) {
                $specLocal->emit($finding);
            }
        }
        // endregion

        return $api->operations;
    }

    /**
     * Whether a manually-instantiated meta rule should run given level and --only/--skip.
     *
     * @param list<string> $only
     * @param list<string> $skip
     */
    private function metaRuleEnabled(Rule $rule, int $level, array $only, array $skip): bool
    {
        $effectiveLevel = $this->registry->effectiveLevelFor($rule->id, $rule->severity);

        return $effectiveLevel->value <= $level
            && ($only === [] || in_array($rule->id, $only, true))
            && !in_array($rule->id, $skip, true);
    }

    /**
     * Maps in-scope operations to tag lists for the coverage denominator.
     * Gates by URI because walkSpec()'s path restriction alone can't exclude empty-glob routes.
     *
     * @param list<OperationNode>      $operations
     * @param null|array<string, true> $allowedRouteUris
     *
     * @return array{0: array<string, list<string>>, 1: array<string, array{file: ?string, line: ?int}>}
     */
    private function collectOperationCoverage(array $operations, ?array $allowedRouteUris, string $specName): array
    {
        $operationTags = [];
        $operationLocations = [];

        foreach ($operations as $operation) {
            if ($allowedRouteUris !== null && !isset($allowedRouteUris[ltrim($operation->pathUri, '/')])) {
                continue;
            }

            $key = CoverageCalculator::operationKey(
                $specName,
                $operation->method,
                $operation->pathUri,
            );

            if ($key !== null) {
                $operationTags[$key] = $operation->tags;
                $operationLocations[$key] = ['file' => $operation->file(), 'line' => $operation->line()];
            }
        }

        return [$operationTags, $operationLocations];
    }

    /**
     * Post-walk pipeline: severity overrides, scope filter, --only/--skip, suppressions, level cutoff.
     *
     * @param list<Finding>              $rawFindings
     * @param list<string>               $only
     * @param list<string>               $skip
     * @param list<SuppressionDirective> $suppressionsAll
     * @param null|array<string, true>   $allowedRouteUris
     * @param array<string, true>        $allowedSchemaClasses
     * @param array<string, true>        $allComponentClasses
     *
     * @return list<Finding>
     */
    private function filterFindings(
        array $rawFindings,
        LintOptions $options,
        int $level,
        array $only,
        array $skip,
        array $suppressionsAll,
        ?array $allowedRouteUris,
        array $allowedSchemaClasses,
        array $allComponentClasses,
    ): array {
        $findings = $this->registry->applyOverrides($rawFindings);

        if ($allowedRouteUris !== null) {
            $findings = array_filter(
                $findings,
                static function (Finding $finding) use (
                    $allowedRouteUris,
                    $allowedSchemaClasses,
                    $allComponentClasses,
                ): bool {
                    $uri = $finding->location->routeUri;

                    if ($uri !== null) {
                        return isset($allowedRouteUris[ltrim($uri, '/')]);
                    }

                    // No routeUri: scope by reachability; unknown class = route-agnostic = keep.
                    $sourceClass = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;

                    if (is_string($sourceClass) && isset($allComponentClasses[$sourceClass])) {
                        return isset($allowedSchemaClasses[$sourceClass]);
                    }

                    return true;
                },
            );
        }

        if ($only !== []) {
            $findings = array_filter(
                $findings,
                static fn(Finding $finding): bool => in_array($finding->ruleId, $only, true),
            );
        }

        if ($skip !== []) {
            $findings = array_filter(
                $findings,
                static fn(Finding $finding): bool
                    => $finding->ruleId === RuleRegistry::EXEMPT_RULE_ID
                    || !in_array($finding->ruleId, $skip, true),
            );
        }

        if ($options->applySuppressions) {
            $findings = $this->applySuppressions(array_values($findings), $suppressionsAll);
        }

        return array_values(
            array_filter(
                $findings,
                static fn(Finding $finding): bool => $finding->severity->value <= $level,
            ),
        );
    }

    /**
     * @param list<Finding>              $findings
     * @param list<SuppressionDirective> $suppressions
     *
     * @return list<Finding>
     */
    private function applySuppressions(array $findings, array $suppressions): array
    {
        if ($suppressions === []) {
            return $findings;
        }

        return array_values(
            array_filter($findings, static function (Finding $finding) use ($suppressions): bool {
                // spec.invalid is never suppressible.
                if ($finding->ruleId === RuleRegistry::EXEMPT_RULE_ID) {
                    return true;
                }

                return !array_any(
                    $suppressions,
                    static fn(SuppressionDirective $directive): bool => $directive->suppresses($finding),
                );
            }),
        );
    }

    /**
     * Installed generator version for the coverage report. Falls back to 'dev' when unresolvable.
     */
    private function generatorVersion(): string
    {
        if (!InstalledVersions::isInstalled('radiergummi/laravel-openapi')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('radiergummi/laravel-openapi') ?? 'dev';
    }

    /**
     * Without a coverage gate: any finding → 1. With a gate: non-zero when below floor or over budget.
     *
     * @param list<Finding> $findings
     */
    private function resolveExitCode(array $findings, CoverageSummary $coverage, LintOptions $options): int
    {
        $minCoverage = $options->minCoverage ?? $this->configuredMinCoverage;
        $maxFindings = $options->maxFindings ?? $this->configuredMaxFindings;

        if ($minCoverage === null && $maxFindings === null) {
            return $findings === [] ? 0 : 1;
        }

        if ($minCoverage !== null && $coverage->coveragePercent < $minCoverage) {
            return 1;
        }

        if ($maxFindings !== null && count($findings) > $maxFindings) {
            return 1;
        }

        return 0;
    }
}
