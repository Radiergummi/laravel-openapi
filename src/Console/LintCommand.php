<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Formatters\CliFormatter;
use Radiergummi\OpenApi\Core\Lint\Formatters\Formatter;
use Radiergummi\OpenApi\Core\Lint\Formatters\GithubFormatter;
use Radiergummi\OpenApi\Core\Lint\Formatters\JsonFormatter;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\RuleCatalogRenderer;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Radiergummi\OpenApi\Core\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Core\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use Laravel\Passport\Passport;
use ReflectionException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function config;
use function explode;
use function fnmatch;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const PHP_EOL;

/**
 * Lints OpenAPI documentation gaps across the API surface.
 */
#[Description('Lint OpenAPI documentation gaps across the API surface')]
#[Signature(
    'openapi:lint
        {--level=1 : Severity preset (0–N or "max" for highest defined)}
        {--format= : Output format (cli|json|github; auto-detected by default)}
        {--only= : Restrict to listed rule IDs (comma-separated)}
        {--skip= : Restrict to listed rule IDs to exclude (comma-separated)}
        {--path= : Restrict to routes matching this URI glob}
        {--diff= : Restrict to routes touched since git-ref (default: merge-base with develop)}
        {--no-suppress : Ignore #[IgnoreLint] attributes}
        {--list : Print the rule catalog instead of linting}',
)]
class LintCommand extends Command
{
    /**
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function handle(
        RouteIntrospector $introspector,
        RuleRegistry $registry,
        SuppressionCollector $suppressionCollector,
        OpenApiRegistry $openApiRegistry,
    ): int {
        if ($this->option('list')) {
            return $this->renderCatalog($registry);
        }

        // Swap FindingsCollector BEFORE resolving the generator, so extractors get the
        // ArrayFindingsCollector instead of the LoggingFindingsCollector. Forget scoped instances
        // to prevent the container from returning a previously cached LoggingFindingsCollector.
        $collector = new ArrayFindingsCollector();

        $this->laravel->forgetScopedInstances();
        $this->laravel->instance(FindingsCollector::class, $collector);

        $generator = $this->laravel->make(OpenApiGenerator::class);
        $descriptors = [];

        foreach ($introspector->discover() as $descriptor) {
            $descriptors[] = $descriptor;
        }

        $descriptors = $this->filterDescriptors($descriptors);
        $filters = $this->buildGeneratorFilters($descriptors);
        $spec = $generator->generate($filters);

        $suppressions = $suppressionCollector->collect($descriptors);

        $only = $this->resolveOnly();
        $skip = $this->resolveSkip();
        $level = $this->resolveLevel(
            $this->input->hasParameterOption('--level')
                ? (string) $this->option('level')
                : (string) config('openapi.lint.level', 0),
            $registry,
        );

        // When --only targets specific rules, surface them regardless of the
        // severity preset. Otherwise selecting a level-2 rule under the default
        // level-0 threshold would silently produce no output.
        if ($only !== []) {
            $level = max($level, $registry->maxLevel());
        }

        $rules = $registry->forLevel($level, only: $only, skip: $skip);

        // MetaSuppressionStale is a PostWalkRule — it cannot be dispatched by the
        // walker because it requires the complete findings set. It is instantiated
        // manually and invoked after the walk. Its ID must still appear in knownRuleIds
        // so that suppressing it does not itself trip meta.unknown-rule.
        $staleChecker = new MetaSuppressionStale();

        $knownRuleIds = [
            ...$registry->knownIds(),
            MetaSuppressionStale::ID,
        ];

        // Build domain tree
        $treeBuilder = new SpecTreeBuilder();
        $api = $treeBuilder->build($spec, $descriptors);

        // Resolve registered OAuth scopes from Passport when it is installed.
        // Passport is an optional dependency: class_exists() on the imported but
        // absent class is safe and simply returns false. Without Passport the
        // scope-coverage rules receive an empty list and skip their checks.
        $registeredScopes = class_exists(Passport::class)
            ? array_keys(Passport::scopes()->keyBy('id')->all())
            : [];

        // Build tree index
        $index = TreeIndex::build(
            api: $api,
            rawSpec: $spec,
            knownRuleIds: $knownRuleIds,
            registeredScopes: $registeredScopes,
        );

        // Build lint context
        $context = new LintContext(
            api: $api,
            index: $index,
            rawSpec: $spec,
            actionDescriptors: $descriptors,
            suppressions: $suppressions,
            payloadClasses: $openApiRegistry->payloadClasses(),
        );

        // Walk tree — single-pass dispatch to visitor-based rules
        $walker = new SpecTreeWalker($rules);

        foreach ($walker->walk($api, $context) as $finding) {
            $collector->emit($finding);
        }

        // Post-walk: MetaSuppressionStale needs the full finding set
        if ($this->metaRuleEnabled($staleChecker, $level, $only, $skip, $registry)) {
            foreach ($staleChecker->check($context, $collector->all()) as $finding) {
                $collector->emit($finding);
            }
        }

        $findings = $collector->all();

        // Remap finding levels before the threshold filter, so an override
        // affects both the cutoff decision and the reported severity.
        $findings = $registry->applyOverrides($findings);

        // Apply --only/--skip to ALL findings (including extractor-emitted)
        if ($only !== []) {
            $findings = array_filter(
                $findings,
                static fn(Finding $finding): bool
                    => in_array(
                        $finding->ruleId,
                        $only,
                        true,
                    ),
            );
        }

        if ($skip !== []) {
            $findings = array_filter(
                $findings,
                static fn(Finding $finding): bool
                    // spec.invalid is never skippable — even if it somehow
                    // survived the resolveSkip() guard, enforce it here too.
                    => $finding->ruleId === RuleRegistry::EXEMPT_RULE_ID
                    || !in_array($finding->ruleId, $skip, true),
            );
        }

        // Apply suppressions
        if (!$this->option('no-suppress')) {
            $findings = $this->applySuppressions($findings, $suppressions);
        }

        // Filter to threshold
        $findings = array_values(
            array_filter(
                $findings,
                static fn(Finding $finding): bool => $finding->level <= $level,
            ),
        );

        $exitCode = $findings === [] ? self::SUCCESS : self::FAILURE;

        $formatter = $this->resolveFormatter();
        $formatter->render(
            $findings,
            $level,
            $exitCode,
            $this->output->getOutput(),
        );

        // Drop the ArrayFindingsCollector instance binding so a later resolve
        // falls back to the scoped LoggingFindingsCollector. Matters only when
        // this command shares a process with other OpenAPI generation work.
        $this->laravel->forgetInstance(FindingsCollector::class);

        return $exitCode;
    }

    /**
     * Resolve the effective --only list, merging CLI input with
     * config('openapi.lint.enabled_rules').
     *
     * - If config is a non-null array AND --only is given: intersection.
     * - If only config is set: use config list.
     * - If only --only is given: use CLI list.
     * - Neither set: empty (no restriction).
     *
     * @return list<string>
     */
    private function resolveOnly(): array
    {
        $cli = $this->parseList($this->option('only'));
        $cfg = config('openapi.lint.enabled_rules');

        if (is_array($cfg)) {
            // Both set: only rules that appear in both are active.
            return $cli !== []
                ? array_values(array_filter($cli, static fn(string $id): bool => in_array($id, $cfg, true)))
                : array_values($cfg);
        }

        // No config allowlist — CLI wins (may be empty, meaning "no restriction").
        return $cli;
    }

    /**
     * Resolve the effective --skip list, merging CLI input with
     * config('openapi.lint.disabled_rules').
     *
     * spec.invalid is unconditionally removed from the result — it cannot be
     * disabled via config or CLI.
     *
     * @return list<string>
     */
    private function resolveSkip(): array
    {
        $cli = $this->parseList($this->option('skip'));
        $cfg = (array) config('openapi.lint.disabled_rules', []);

        $merged = array_values(array_unique(array_merge($cli, $cfg)));

        // spec.invalid is never disablable.
        return array_values(
            array_filter(
                $merged,
                static fn(string $id): bool => $id !== RuleRegistry::EXEMPT_RULE_ID,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function parseList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(
            array_filter(array_map('trim', explode(',', $raw))),
        );
    }

    private function resolveLevel(string $raw, RuleRegistry $registry): int
    {
        return $raw === 'max' ? $registry->maxLevel() : max(0, (int) $raw);
    }

    /**
     * Decide whether a manually-instantiated meta rule should run, honouring the
     * requested severity level and the --only/--skip allow/deny lists.
     *
     * @param list<string> $only
     * @param list<string> $skip
     */
    private function metaRuleEnabled(
        Rule $rule,
        int $level,
        array $only,
        array $skip,
        RuleRegistry $registry,
    ): bool {
        $effectiveLevel = $registry->effectiveLevelFor($rule->id(), $rule->level());

        return $effectiveLevel <= $level
            && ($only === [] || in_array($rule->id(), $only, true))
            && !in_array($rule->id(), $skip, true);
    }

    private function renderCatalog(RuleRegistry $registry): int
    {
        $format = $this->option('format');

        if (!is_string($format) || $format === '') {
            $format = $this->output->isDecorated() ? 'cli' : 'json';
        }

        $this->output->write(
            (new RuleCatalogRenderer())->render($registry, $format),
        );

        return self::SUCCESS;
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    private function resolveFormatter(): Formatter
    {
        $format = $this->option('format');

        if ($format === null || $format === '') {
            $format = match (true) {
                getenv('GITHUB_ACTIONS') === 'true' => 'github',
                $this->output->isDecorated() => 'cli',
                default => 'json',
            };
        }

        return match ($format) {
            'cli' => $this->laravel->make(CliFormatter::class),
            'json' => $this->laravel->make(JsonFormatter::class),
            'github' => $this->laravel->make(GithubFormatter::class),
            default => throw new InvalidArgumentException(
                "Unknown format: {$format}",
            ),
        };
    }

    /**
     * @param list<ActionDescriptor> $descriptors
     *
     * @return list<ActionDescriptor>
     *
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    private function filterDescriptors(array $descriptors): array
    {
        // Always filter out routes that can't produce actionable findings: closure routes (no
        // controller), vendor routes (can't be fixed), and framework fallback routes.
        $descriptors = array_values(
            array_filter($descriptors, static function (ActionDescriptor $descriptor): bool {
                // Skip closure routes (no controller at all)
                if ($descriptor->controller === null && $descriptor->method === null) {
                    return false;
                }

                // Determine the source file from either method or controller
                $file = $descriptor->method?->getFileName()
                    ?? $descriptor->controller?->getFileName();

                if ($file === false || $file === null) {
                    return false;
                }

                // Skip routes whose controller lives in vendor/
                if (str_contains($file, '/vendor/')) {
                    return false;
                }

                return true;
            }),
        );

        $pathPattern = $this->option('path');
        $diffRef = $this->option('diff');

        // `--diff` is value-optional: a bare `--diff` yields a null value but is
        // still "requested" and must fall back to the default ref. option()
        // alone can't tell bare-flag from absent, so check the raw input.
        $diffRequested = $this->input->hasParameterOption('--diff');

        if (is_string($pathPattern) && $pathPattern !== '') {
            $descriptors = array_values(
                array_filter(
                    $descriptors,
                    static fn(ActionDescriptor $descriptor): bool
                        => fnmatch(
                            $pathPattern,
                            $descriptor->route->uri(),
                        ),
                ),
            );
        }

        if ($diffRequested) {
            $ref = $diffRef === null || $diffRef === ''
                ? $this->resolveDefaultDiffRef()
                : (string) $diffRef;
            $changedFiles = $this->changedFilesSince($ref);

            if (!$this->infraTouched($changedFiles)) {
                return array_values(
                    array_filter(
                        $descriptors,
                        fn(ActionDescriptor $descriptor): bool
                            => $this->descriptorAffectedByChanges(
                                $descriptor,
                                $changedFiles,
                            ),
                    ),
                );
            }
        }

        return $descriptors;
    }

    /**
     * Build generator filters that restrict generation to the filtered descriptors.
     *
     * Always excludes vendor routes — their controllers can't be annotated and produce only noise
     * during lint. Additional --path/--diff whitelist filters are layered on top.
     *
     * @param list<ActionDescriptor> $descriptors
     *
     * @return list<callable(ActionDescriptor): bool>
     */
    private function buildGeneratorFilters(array $descriptors): array
    {
        $filters = [];

        // Always exclude vendor and unresolvable routes from the generator pass.
        // This prevents extractor-emitted findings (response.empty, request.empty, etc.) from
        // accumulating for routes whose controllers live in vendor/ and can't be annotated
        // or suppressed.
        //
        // NOTE: generator filters use *exclude* semantics — returning true means
        // "skip this descriptor" (the opposite of array_filter's "keep" polarity).
        $filters[] = static function (ActionDescriptor $descriptor): bool {
            if ($descriptor->controller === null && $descriptor->method === null) {
                return true; // exclude: no controller
            }

            $file = $descriptor->method?->getFileName()
                ?? $descriptor->controller?->getFileName();

            if ($file === false || $file === null) {
                return true; // exclude: unresolvable file
            }

            return str_contains($file, '/vendor/'); // exclude if vendor
        };

        if (
            $this->option('path') !== null
            || $this->input->hasParameterOption('--diff')
        ) {
            $allowedUris = [];

            foreach ($descriptors as $descriptor) {
                $key = sprintf(
                    '%s|%s',
                    $descriptor->route->uri(),
                    implode(',', $descriptor->route->methods()),
                );
                $allowedUris[$key] = true;
            }

            $filters[] = static fn(ActionDescriptor $descriptor): bool
                => !isset(
                    $allowedUris[sprintf(
                        '%s|%s',
                        $descriptor->route->uri(),
                        implode(',', $descriptor->route->methods()),
                    )],
                );
        }

        return $filters;
    }

    /**
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    private function resolveDefaultDiffRef(): string
    {
        $process = new Process(['git', 'merge-base', 'HEAD', 'develop']);
        $process->run();

        $output = trim($process->getOutput());

        return $output !== '' ? $output : 'HEAD~1';
    }

    /**
     * @return list<string>
     *
     * @throws LogicException
     * @throws ProcessSignaledException
     * @throws ProcessStartFailedException
     * @throws ProcessTimedOutException
     * @throws RuntimeException
     */
    private function changedFilesSince(string $ref): array
    {
        $process = new Process(['git', 'diff', '--name-only', $ref . '...HEAD']);
        $process->run();

        return array_values(
            array_filter(array_map('trim', explode(PHP_EOL, $process->getOutput()))),
        );
    }

    /**
     * @param list<string> $changedFiles
     */
    private function infraTouched(array $changedFiles): bool
    {
        foreach ($changedFiles as $file) {
            if (str_starts_with($file, 'app/Support/OpenApi/')) {
                return true;
            }

            if ($file === 'config/openapi.php') {
                return true;
            }

            if ($file === 'app/Providers/OpenApiServiceProvider.php') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $changedFiles
     */
    private function descriptorAffectedByChanges(ActionDescriptor $descriptor, array $changedFiles): bool
    {
        if ($descriptor->method === null) {
            return false;
        }

        $controllerFile = $descriptor->method->getFileName();

        if ($controllerFile === false) {
            return false;
        }

        // Normalize to be relative to project root
        $basePath = base_path() . '/';

        if (str_starts_with($controllerFile, $basePath)) {
            $controllerFile = substr($controllerFile, strlen($basePath));
        }

        return in_array($controllerFile, $changedFiles, true);
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
            array_filter($findings, static function (Finding $finding) use (
                $suppressions,
            ): bool {
                // spec.invalid is never suppressible
                if ($finding->ruleId === RuleRegistry::EXEMPT_RULE_ID) {
                    return true;
                }

                foreach ($suppressions as $directive) {
                    if ($directive->suppresses($finding)) {
                        return false;
                    }
                }

                return true;
            }),
        );
    }
}
