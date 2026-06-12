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
 * Orchestrates one lint run against the application's routes.
 *
 * Extracted from {@see LintCommand} so the lint pipeline is unit-testable independent of the
 * artisan command and reusable from other entry points (programmatic consumers, custom CLI
 * wrappers, HTTP endpoints). The command becomes a thin adapter: it parses options into
 * {@see LintOptions}, calls {@see run()}, and renders the resulting {@see LintResult} via a
 * {@see Formatters\Formatter}.
 *
 * The runner binds an {@see ArrayFindingsCollector} as the active {@see FindingsCollector}
 * before resolving the generator so extractor-emitted findings are captured rather than logged.
 * The previous binding is restored after the run completes.
 *
 * The run has two phases:
 *  1. Pre-build: {@see Visitors\PreBuildRule} instances inspect config + descriptors once.
 *  2. Per-spec: for each target spec, the generator builds the document and the tree walker
 *     dispatches visitor rules; findings are tagged with the spec name via
 *     {@see Finding::withSpec()}.
 */
#[Scoped]
final readonly class LintRunner
{
    /**
     * @param null|list<string> $enabledRules          `openapi.lint.enabled_rules` — null when unset
     *                                                 (distinct from `[]`, which means "all disabled").
     * @param list<string>      $disabledRules         `openapi.lint.disabled_rules`.
     * @param int|string        $configuredLevel       `openapi.lint.level` — numeric or the literal
     *                                                 string `"max"`.
     * @param ?float            $configuredMinCoverage `openapi.lint.min_coverage` — gate floor, null
     *                                                 when unset.
     * @param ?int              $configuredMaxFindings `openapi.lint.max_findings` — gate budget, null
     *                                                 when unset.
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

        $descriptors = $this->collectDescriptors($options);

        // When --path or --diff narrows the route set, build the allowed-URI set up front so
        // post-processing can drop findings emitted by generation stages (e.g. RequestBodyExtractor,
        // ValidationRulesToSchema) that target routes outside the filter. The tree-walk path
        // restricts $document->paths before walking, but stage-emitted findings bypass that gate.
        $allowedRouteUris = null;

        // Schema-derived generation-time findings (rule.unknown, rule.invalid-enum-value,
        // request-body.schema-degraded) carry no routeUri — the schema is built once and shared
        // across routes — so they cannot be scoped by URI. Instead they are scoped by reachability:
        // $allowedSchemaClasses holds the component classes referenced by in-scope routes, and
        // $allComponentClasses holds every component class seen (the confidence gate that stops us
        // dropping a finding whose source class we cannot place as a component). Both are populated
        // per spec inside the loop below, and only consulted when a route filter is active.
        $allowedSchemaClasses = [];
        $allComponentClasses = [];

        if ($options->isScoped) {
            $allowedRouteUris = self::descriptorUriSet($descriptors);
        }

        // endregion

        // region Option resolution

        $only = $this->resolveOnly($options->only);
        $skip = $this->resolveSkip($options->skip);
        $level = $this->resolveLevel($options);

        // When --only targets specific rules, surface them regardless of the severity preset.
        if ($only !== []) {
            $level = max($level, $this->registry->maxLevel());
        }

        $rules = $this->registry->forLevel($level, only: $only, skip: $skip);

        if (!$options->validateSpec) {
            $rules = array_values(
                array_filter(
                    $rules,
                    static fn(Rule $rule): bool => $rule->id() !== RuleRegistry::EXEMPT_RULE_ID,
                ),
            );
        }

        // Stages to exclude when building the inference-only control document for this run, unioned
        // across every active rule that compares against inference (the migration family). Empty
        // when no such rule is active, so the control generation stays unbuilt and unpaid.
        $inferenceExcludedStages = $this->inferenceExcludedStages($rules);

        // endregion

        // region Pre-build phase — runs once, findings carry spec: null

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

        // Operation key => tag list, accumulated across every target spec. Feeds the coverage
        // calculator. Built from the same in-scope tree the walk uses, so it honours --path/--diff.
        /** @var array<string, list<string>> $operationTags */
        $operationTags = [];

        // Operation key => controller source location, for the line-keyed coverage reports
        // (Cobertura/LCOV). Collected in the same pass; null file/line for closure routes.
        /** @var array<string, array{file: ?string, line: ?int}> $operationLocations */
        $operationLocations = [];

        foreach ($targets as $spec) {
            // Bind a spec-local collector BEFORE generateOne so stage-emitted findings
            // (e.g. ValidationRulesToSchema, ErrorResponseInferenceStage) land alongside the
            // tree-walk findings. The orchestrator's forgetScopedInstances() preserves the
            // current FindingsCollector binding, so this instance survives generation.
            // Spec-local stays un-decorated; the drain below goes through $emit so each
            // finding fires LintFindingEmitted exactly once, with the spec tag attached.
            $specLocal = new ArrayFindingsCollector();
            $this->container->forgetInstance(FindingsCollector::class);
            $this->container->instance(FindingsCollector::class, $specLocal);

            $document = $this->orchestrator->generateOne($spec->name);

            // generateOne() calls forgetScopedInstances() internally, which replaces the
            // ComponentSchemaRegistry scoped instance. Re-resolve so componentClassMap() reflects
            // the schemas registered during generation, not the stale pre-generation instance.
            $liveRegistry = $this->container->make(ComponentSchemaRegistry::class);
            $classMap = $liveRegistry->componentClassMap();

            // Accumulate the schema-class scoping sets for this spec's document. Reachability
            // selects in-scope path items by URI (shared inScopePathItems helper), so it is
            // independent of whether the tree walk has already restricted $document->paths.
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

            // Build the inference-only view for the migration family, at this safe boundary (after
            // the lint document and its class map are captured as locals, before the walk). The
            // orchestrator owns the scoped-state reset; no rule re-enters the pipeline. The same
            // control document feeds both the schema-level and operation-level redundancy rules.
            $inference = $inferenceExcludedStages !== []
                ? InferenceView::from($this->orchestrator->inferenceOnly($spec->name, $inferenceExcludedStages))
                : new InferenceView();

            $operations = $this->walkSpec(
                $document,
                $descriptors,
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
     * Discover every Laravel route, drop global-filter rejects (vendor routes like Telescope, Nova,
     * Passport, Ignition and any user-configured filter), then apply the --path/--files/--diff
     * narrowing. Per-spec {@see InclusionEvaluator::decide()} still re-filters per (route × spec)
     * inside the generator, so this is the single-pass equivalent.
     *
     * @return list<ActionDescriptor>
     *
     * @throws LogicException
     * @throws ProcessRuntimeException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws ReflectionException
     * @throws UnexpectedValueException
     */
    private function collectDescriptors(LintOptions $options): array
    {
        $descriptors = [];

        foreach ($this->introspector->discover() as $descriptor) {
            if (!$this->evaluator->passesGlobalFilters($descriptor)) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        return $this->routeFilter->filter(
            descriptors: $descriptors,
            uriGlob: $options->uriGlob,
            files: $options->files,
            diff: $options->diff,
        );
    }

    /**
     * In-scope route URIs (leading slash trimmed) for the given descriptors. The single source of
     * the "which routes are in scope" set — shared by the `--path`/`--diff` finding filter, the
     * tree-walk path restriction, and schema reachability — so they cannot diverge on slash
     * handling.
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
     * Resolve the effective --only list, merging CLI input with config('openapi.lint.enabled_rules').
     *
     * - If config is a non-null array AND CLI is non-empty: intersection.
     * - If only config is set: use config list.
     * - If only CLI is set: use CLI list.
     * - Neither set: empty (no restriction).
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
     * Expand `*`-globbed rule-ID patterns against the registered rule IDs, so a family pattern like
     * `migration.*` selects every rule in that family. Non-glob entries pass through verbatim
     * (an unknown exact ID still matches nothing, as before); a glob that matches no registered
     * rule is kept literally, so it too matches nothing rather than collapsing to "no filter".
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
     * Resolve the effective --skip list, merging CLI input with config('openapi.lint.disabled_rules').
     *
     * spec.invalid is unconditionally removed — it cannot be disabled.
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
     * The union of stages to exclude when building the inference-only control document, gathered
     * from every active rule that declares it compares against inference. Empty when no such rule is
     * active — the signal for the per-spec loop to skip the control generation entirely.
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
     * Component classes reachable from the in-scope operations, transitively through
     * component-to-component `$ref`s. Schema-derived findings are class-keyed (a FormRequest or
     * Data class is built once and `$ref`'d by many routes), so they are scoped by membership in
     * this set rather than by a single routeUri.
     *
     * Seeding from in-scope path items only — not the whole document, whose component pool still
     * holds out-of-scope schemas — keeps a schema in scope exactly when an in-scope route reaches
     * it.
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
     * The document's path items whose URI is in `$allowedRouteUris` (leading slash trimmed).
     *
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
     * Schema-component names referenced via `$ref` anywhere within the given annotation subtree.
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
     * Run the tree walk, RouteRule pass, and MetaSuppressionStale for one spec.
     *
     * The caller is responsible for binding {@see $specLocal} as the active
     * {@see FindingsCollector} before generation so extractor-emitted findings land in the same
     * bucket as the tree-walk findings; this method only emits into the bucket and leaves draining
     * to the caller.
     *
     * @param list<Rule>                  $rules
     * @param list<ActionDescriptor>      $descriptors
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

        // When a path filter is active, $descriptors contains only the allowed routes.
        // The generated document includes all routes. Restrict $document->paths to only those whose
        // URI appears in the allowed set so rules don't fire on routes that were excluded by
        // `--path` or `--diff`.
        if ($descriptors !== [] && is_array($document->paths)) {
            $document->paths = self::inScopePathItems($document, self::descriptorUriSet($descriptors));
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
            foreach ($descriptors as $descriptor) {
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
     * Decide whether a manually-instantiated meta rule should run, honoring the requested
     * severity level and the --only/--skip allow/deny lists.
     *
     * @param list<string> $only
     * @param list<string> $skip
     */
    private function metaRuleEnabled(Rule $rule, int $level, array $only, array $skip): bool
    {
        $effectiveLevel = $this->registry->effectiveLevelFor($rule->id(), $rule->level());

        return $effectiveLevel <= $level
            && ($only === [] || in_array($rule->id(), $only, true))
            && !in_array($rule->id(), $skip, true);
    }

    /**
     * Map in-scope operations to their tag lists for the coverage denominator. When a route filter
     * is active, an out-of-scope operation must not count: {@see walkSpec()} already restricts
     * $document->paths when descriptors match, but an empty match (e.g. a glob that hits nothing)
     * leaves every operation in place, so the URI is gated explicitly here.
     *
     * @param list<OperationNode>      $operations
     * @param null|array<string, true> $allowedRouteUris
     *
     * @return array{0: array<string, list<string>>, 1: array<string, array{file: ?string, line: ?int}>}
     *                                                                                                   [operation key
     *                                                                                                   => tags,
     *                                                                                                   operation key
     *                                                                                                   => source
     *                                                                                                   location]
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
     * Apply the post-walk finding pipeline: severity overrides, route/schema scope filtering (when
     * --path/--diff is active), --only/--skip, suppressions, and the level cutoff.
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

                    // No routeUri: this is either a schema-derived generation finding (scope it by
                    // the schema's reachability from in-scope routes) or a genuinely route-agnostic
                    // finding (pre-build, spec-level — always kept). A source class we don't
                    // recognise as a component is treated as route-agnostic and kept, so an
                    // in-scope finding is never hidden because we couldn't place its schema.
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
                static fn(Finding $finding): bool => $finding->level <= $level,
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
     * The installed generator version, stamped into the coverage report so cross-version coverage
     * deltas are recognised as non-comparable. Falls back to 'dev' when the package is not resolvable
     * as a Composer dependency.
     */
    private function generatorVersion(): string
    {
        if (!InstalledVersions::isInstalled('radiergummi/laravel-openapi')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('radiergummi/laravel-openapi') ?? 'dev';
    }

    /**
     * Exit code for the run. With no coverage gate configured the legacy rule applies (any finding
     * → 1). When a gate is active (--min-coverage / --max-findings, from CLI or config) the gate
     * replaces that rule: non-zero only when coverage is below the floor or findings exceed the
     * budget — findings alone no longer fail.
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
