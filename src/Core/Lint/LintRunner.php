<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RouteRule;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function app;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function config;
use function in_array;
use function is_array;
use function ltrim;
use function max;

/**
 * Orchestrates one lint run against the application's routes.
 *
 * Extracted from {@see \Radiergummi\OpenApi\Console\LintCommand} so the lint pipeline is
 * unit-testable independent of the artisan command and reusable from other entry points
 * (programmatic consumers, custom CLI wrappers, HTTP endpoints). The command becomes a thin
 * adapter: it parses options into {@see LintOptions}, calls {@see run()}, and renders the
 * resulting {@see LintResult} via a {@see Formatters\Formatter}.
 *
 * The runner binds an {@see ArrayFindingsCollector} as the active {@see FindingsCollector}
 * before resolving the generator so extractor-emitted findings are captured rather than logged.
 * The previous binding is restored after the run completes.
 *
 * The run has two phases:
 *  1. Pre-build: {@see Rules\Visitors\PreBuildRule} instances inspect config + descriptors once.
 *  2. Per-spec: for each target spec, the generator builds the document and the tree walker
 *     dispatches visitor rules; findings are tagged with the spec name via {@see Finding::withSpec()}.
 */
final readonly class LintRunner
{
    public function __construct(
        private Container $container,
        private RouteIntrospector $introspector,
        private RuleRegistry $registry,
        private SuppressionCollector $suppressionCollector,
        private OpenApiRegistry $openApiRegistry,
        private LintRouteFilter $routeFilter,
        private SpecRegistry $specRegistry,
        private OpenApiGenerationOrchestrator $orchestrator,
    ) {}

    /**
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
        $collector = $this->installArrayCollector();

        try {
            return $this->runWithCollector($options, $collector);
        } finally {
            $this->container->forgetInstance(FindingsCollector::class);
        }
    }

    /**
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
    private function runWithCollector(LintOptions $options, ArrayFindingsCollector $collector): LintResult
    {
        // region Descriptor collection + path/diff filtering

        $descriptors = [];

        foreach ($this->introspector->discover() as $descriptor) {
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
            $preBuildRule->checkConfiguration($this->specRegistry, $descriptors, $collector);
        }

        // endregion

        // region Per-spec phase

        $targets = $options->spec !== null
            ? [$this->specRegistry->get($options->spec)]
            : $this->specRegistry->all();

        $suppressions = $this->suppressionCollector->collect($descriptors);

        foreach ($targets as $spec) {
            // Bind a spec-local collector BEFORE generateOne so extractor-emitted findings
            // (e.g. ValidationRulesToSchema, StandardResponsesExtractor) land alongside the
            // tree-walk findings. The orchestrator's forgetScopedInstances() preserves the
            // current FindingsCollector binding, so this instance survives generation.
            $specLocal = new ArrayFindingsCollector();
            $this->container->forgetInstance(FindingsCollector::class);
            $this->container->instance(FindingsCollector::class, $specLocal);

            $document = $this->orchestrator->generateOne($spec->name, app()->environment());

            $this->walkSpec($document, $descriptors, $rules, $suppressions, $specLocal, $level, $only, $skip);

            foreach ($specLocal->all() as $finding) {
                $collector->emit($finding->withSpec($spec->name));
            }
        }

        // endregion

        // region Post-processing: overrides, --only/--skip, suppressions, level filter

        $findings = $this->registry->applyOverrides($collector->all());

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
            $findings = $this->applySuppressions(array_values($findings), $suppressions);
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
     * Run the tree walk + RouteRule pass + MetaSuppressionStale for one spec.
     *
     * The caller is responsible for binding {@see $specLocal} as the active
     * {@see FindingsCollector} before generation so extractor-emitted findings land in the
     * same bucket as the tree-walk findings; this method only emits into the bucket and
     * leaves draining to the caller.
     *
     * @param list<Rule>                 $rules
     * @param list<ActionDescriptor>     $descriptors
     * @param list<SuppressionDirective> $suppressions
     * @param list<string>               $only
     * @param list<string>               $skip
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
    ): void {
        // region Tree walk

        $staleChecker = new MetaSuppressionStale();

        $knownRuleIds = [
            ...$this->registry->knownIds(),
            MetaSuppressionStale::ID,
        ];

        // When a path filter is active, $descriptors contains only the allowed routes.
        // The generated document includes all routes. Restrict $document->paths to only
        // those whose URI appears in the allowed set so rules don't fire on routes that
        // were excluded by --path or --diff.
        if ($descriptors !== [] && is_array($document->paths)) {
            $allowedUris = [];

            foreach ($descriptors as $descriptor) {
                $allowedUris['/' . ltrim($descriptor->route->uri(), '/')] = true;
            }

            $document->paths = array_values(array_filter(
                $document->paths,
                static fn(OA\PathItem $p): bool
                    => $p->path !== Generator::UNDEFINED
                    && isset($allowedUris[$p->path]),
            ));
        }

        $treeBuilder = new SpecTreeBuilder();
        $api = $treeBuilder->build($document, $descriptors);

        $registeredScopes = class_exists(Passport::class)
            ? array_keys(Passport::scopes()->keyBy('id')->all())
            : [];

        $index = TreeIndex::build(
            api: $api,
            rawSpec: $document,
            knownRuleIds: $knownRuleIds,
            registeredScopes: $registeredScopes,
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

        $routeRules = array_values(array_filter(
            $rules,
            static fn(Rule $rule): bool => $rule instanceof RouteRule,
        ));

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
     * Swap the {@see FindingsCollector} binding from the scoped
     * {@see LoggingFindingsCollector} to an {@see ArrayFindingsCollector} for the duration
     * of this run. The per-spec loop pins its own collector before generation; the
     * orchestrator's forgetScopedInstances() preserves whatever override is current, so
     * scoped extractor instances are recreated with fresh wiring when generation runs.
     */
    private function installArrayCollector(): ArrayFindingsCollector
    {
        $collector = new ArrayFindingsCollector();
        $this->container->instance(FindingsCollector::class, $collector);

        return $collector;
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
        $cfg = config('openapi.lint.enabled_rules');

        if (is_array($cfg)) {
            return $cli !== []
                ? array_values(array_filter($cli, static fn(string $id): bool => in_array($id, $cfg, true)))
                : array_values($cfg);
        }

        return $cli;
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
        $cfg = (array) config('openapi.lint.disabled_rules', []);

        $merged = array_values(array_unique(array_merge($cli, $cfg)));

        return array_values(
            array_filter(
                $merged,
                static fn(string $id): bool => $id !== RuleRegistry::EXEMPT_RULE_ID,
            ),
        );
    }

    private function resolveLevel(LintOptions $options): int
    {
        $raw = $options->level ?? config('openapi.lint.level', 0);

        return $raw === 'max' ? $this->registry->maxLevel() : max(0, (int) $raw);
    }

    /**
     * Decide whether a manually-instantiated meta rule should run, honouring the requested
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
