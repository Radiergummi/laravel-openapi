<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\Visitors\RouteRule;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
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
use function array_unique;
use function array_values;
use function class_exists;
use function in_array;
use function is_array;
use function ltrim;
use function max;

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
     * @param null|list<string> $enabledRules    `openapi.lint.enabled_rules` — null when unset
     *                                           (distinct from `[]`, which means "all disabled").
     * @param list<string>      $disabledRules   `openapi.lint.disabled_rules`.
     * @param int|string        $configuredLevel `openapi.lint.level` — numeric or the literal
     *                                           string `"max"`.
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
        #[Config('openapi.lint.enabled_rules')]
        private ?array $enabledRules = null,
        #[Config('openapi.lint.disabled_rules', [])]
        private array $disabledRules = [],
        #[Config('openapi.lint.level', 0)]
        private int|string $configuredLevel = 0,
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
        $emit = new EventDispatchingFindingsCollector($inner, $this->events);
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

        $descriptors = [];

        // The introspector yields every Laravel route; vendor routes (Telescope, Nova, Passport,
        // Ignition) and any user-configured filter rejects are discarded here so the pre-build
        // rules and tree walk only see routes that could plausibly belong to a spec. Per-spec
        // InclusionEvaluator::decide() still runs inside the generator and re-filters per
        // (route × spec), so this is the single-pass equivalent.
        foreach ($this->introspector->discover() as $descriptor) {
            if (!$this->evaluator->passesGlobalFilters($descriptor)) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        $descriptors = $this->routeFilter->filter(
            descriptors: $descriptors,
            path: $options->path,
            diffEnabled: $options->diffEnabled,
            diffRef: $options->diffRef,
        );

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

            $componentDirectives = $this->suppressionCollector->collectFromComponentSchemas($classMap);
            $specSuppressions = [...$descriptorDirectives, ...$componentDirectives];
            $suppressionsAll = [...$suppressionsAll, ...$componentDirectives];

            $this->walkSpec(
                $document,
                $descriptors,
                $rules,
                $specSuppressions,
                $specLocal,
                $level,
                $only,
                $skip,
                componentClassMap: $classMap,
            );

            foreach ($specLocal->all() as $finding) {
                $emit->emit($finding->withSpec($spec->name));
            }
        }

        // endregion

        // region Post-processing: overrides, --only/--skip, suppressions, level filter

        $findings = $this->registry->applyOverrides($inner->all());

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

        $findings = array_values(
            array_filter(
                $findings,
                static fn(Finding $finding): bool => $finding->level <= $level,
            ),
        );

        // endregion

        $exitCode = $findings === [] ? 0 : 1;

        return new LintResult($findings, $level, $exitCode);
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
        if ($this->enabledRules === null) {
            return $cli;
        }

        return $cli !== []
            ? array_values(
                array_filter(
                    $cli,
                    fn(string $id): bool => in_array($id, $this->enabledRules, true),
                ),
            )
            : array_values($this->enabledRules);
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
        $merged = array_values(array_unique(array_merge($cli, $this->disabledRules)));

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
    ): void {
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
            $allowedUris = [];

            foreach ($descriptors as $descriptor) {
                $allowedUris['/' . ltrim($descriptor->route->uri(), '/')] = true;
            }

            $document->paths = array_values(
                array_filter(
                    $document->paths,
                    static fn(OA\PathItem $p): bool
                        => $p->path !== Generator::UNDEFINED
                        && isset($allowedUris[$p->path]),
                ),
            );
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
            payloadClasses: $this->openApiRegistry->payloadClasses(),
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
}
