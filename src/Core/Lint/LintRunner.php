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
use Laravel\Passport\Passport;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RouteRule;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
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
use function config;
use function in_array;
use function is_array;
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
    ) {}

    /**
     * @throws BindingResolutionException
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
        $generator = $this->container->make(OpenApiGenerator::class);

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

        $generatorFilters = $this->routeFilter->buildGeneratorFilters(
            descriptors: $descriptors,
            path: $options->path,
            diffEnabled: $options->diffEnabled,
        );

        $spec = $generator->generate($generatorFilters);
        $suppressions = $this->suppressionCollector->collect($descriptors);

        $only = $this->resolveOnly($options->only);
        $skip = $this->resolveSkip($options->skip);
        $level = $this->resolveLevel($options);

        // When --only targets specific rules, surface them regardless of the severity preset.
        // Otherwise selecting a level-2 rule under the default level-0 threshold would silently
        // produce no output.
        if ($only !== []) {
            $level = max($level, $this->registry->maxLevel());
        }

        $rules = $this->registry->forLevel($level, only: $only, skip: $skip);

        // MetaSuppressionStale is a PostWalkRule — it cannot be dispatched by the walker because
        // it requires the complete findings set. Instantiate manually and invoke after the walk.
        // Its ID must still appear in knownRuleIds so that suppressing it does not itself trip
        // meta.unknown-rule.
        $staleChecker = new MetaSuppressionStale();

        $knownRuleIds = [
            ...$this->registry->knownIds(),
            MetaSuppressionStale::ID,
        ];

        $treeBuilder = new SpecTreeBuilder();
        $api = $treeBuilder->build($spec, $descriptors);

        // Passport is an optional dependency: class_exists() on the imported but absent class is
        // safe and simply returns false. Without Passport the scope-coverage rules receive an
        // empty list and skip their checks.
        $registeredScopes = class_exists(Passport::class)
            ? array_keys(Passport::scopes()->keyBy('id')->all())
            : [];

        $index = TreeIndex::build(
            api: $api,
            rawSpec: $spec,
            knownRuleIds: $knownRuleIds,
            registeredScopes: $registeredScopes,
        );

        $context = new LintContext(
            api: $api,
            index: $index,
            rawSpec: $spec,
            actionDescriptors: $descriptors,
            suppressions: $suppressions,
            payloadClasses: $this->openApiRegistry->payloadClasses(),
        );

        // Walk tree — single-pass dispatch to visitor-based rules.
        $walker = new SpecTreeWalker($rules);

        foreach ($walker->walk($api, $context) as $finding) {
            $collector->emit($finding);
        }

        // Walk descriptors — second pass for RouteRule-implementing rules that need to inspect
        // ActionDescriptors directly (e.g. visibility rules, which see hidden routes that never
        // enter the spec tree).
        $routeRules = array_values(array_filter(
            $rules,
            static fn(Rule $rule): bool => $rule instanceof RouteRule,
        ));

        if ($routeRules !== []) {
            foreach ($descriptors as $descriptor) {
                $defaults = FindingLocation::fromDescriptor($descriptor);

                foreach ($routeRules as $rule) {
                    foreach ($rule->checkRoute($descriptor, $context) as $finding) {
                        $collector->emit($finding->withLocationDefaults($defaults));
                    }
                }
            }
        }

        // Post-walk: MetaSuppressionStale needs the full finding set.
        if ($this->metaRuleEnabled($staleChecker, $level, $only, $skip)) {
            foreach ($staleChecker->check($context, $collector->all()) as $finding) {
                $collector->emit($finding);
            }
        }

        $findings = $this->registry->applyOverrides($collector->all());

        // Apply --only/--skip to ALL findings (including extractor-emitted).
        if ($only !== []) {
            $findings = array_filter(
                $findings,
                static fn(Finding $finding): bool => in_array($finding->ruleId, $only, true),
            );
        }

        if ($skip !== []) {
            $findings = array_filter(
                $findings,
                // spec.invalid is never skippable — even if it somehow survived the resolveSkip()
                // guard, enforce it here too.
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

        $exitCode = $findings === [] ? 0 : 1;

        return new LintResult($findings, $level, $exitCode);
    }

    /**
     * Swap the {@see FindingsCollector} binding from the scoped
     * {@see LoggingFindingsCollector} to an {@see ArrayFindingsCollector} for the duration
     * of this run. Scoped instances are forgotten first so callers that already resolved the
     * generator in this scope pick up the new collector on the next resolve.
     */
    private function installArrayCollector(): ArrayFindingsCollector
    {
        $collector = new ArrayFindingsCollector();

        $this->container->forgetScopedInstances();
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
