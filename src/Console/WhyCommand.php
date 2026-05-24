<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Radiergummi\OpenApi\Core\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

use function app;
use function array_map;
use function array_values;
use function count;
use function implode;
use function str_contains;

/**
 * OpenAPI Why Command
 *
 * Explains whether and why a given route is included or excluded across all defined specs.
 * Accepts a route name (exact match) or a URI substring. If the substring matches multiple
 * routes, the command lists the candidates and exits non-zero.
 *
 * @bundle Radiergummi\OpenApi\Console
 */
#[Signature('openapi:why
        {route : Route name (exact match) or URI substring.}
        {--for-env= : Override the environment for Hide/Expose evaluation.}')]
#[Description('Explain inclusion of a route across all defined specs')]
class WhyCommand extends Command
{
    public const string ARGUMENT_ROUTE = 'route';

    /**
     * `--env` is reserved by Laravel for the global "boot in environment X" switch, so we cannot
     * reuse it. `--for-env` carries the same intent: override the environment used for
     * `#[Hide]` / `#[Expose]` resolution without changing `APP_ENV` for the rest of the run.
     */
    public const string OPTION_FOR_ENV = 'for-env';

    public function handle(
        RouteIntrospector $introspector,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
    ): int {
        $query = (string) $this->argument(self::ARGUMENT_ROUTE);
        $env = (string) ($this->option(self::OPTION_FOR_ENV) ?? app()->environment());

        $candidates = $this->findCandidates($introspector, $query);

        if ($candidates === []) {
            $this->components->error("No route matched '{$query}'.");

            return self::FAILURE;
        }

        if (count($candidates) > 1) {
            $this->components->error("Ambiguous route '{$query}'. Candidates:");

            foreach ($candidates as $d) {
                $this->line('  - ' . ($d->route->getName() ?? '(unnamed)') . '  ' . $d->route->uri());
            }

            return self::FAILURE;
        }

        $descriptor = $candidates[0];
        $this->printHeader($descriptor, $env);

        $included = [];

        foreach ($registry->all() as $spec) {
            $decision = $evaluator->decide($descriptor, $spec, $env);
            $this->printSpecDecision($spec->name, $decision);

            if ($decision->included) {
                $included[] = $spec->name;
            }
        }

        $this->line('');
        $this->line('Result: ' . ($included !== []
            ? 'included in [' . implode(', ', $included) . ']'
            : 'excluded from all specs'));

        return self::SUCCESS;
    }

    // region Private helpers

    /**
     * @return list<ActionDescriptor>
     */
    private function findCandidates(RouteIntrospector $introspector, string $query): array
    {
        $exact = [];
        $substring = [];

        foreach ($introspector->discover() as $descriptor) {
            if ($descriptor->route->getName() === $query) {
                $exact[] = $descriptor;

                continue;
            }

            if (str_contains($descriptor->route->uri(), $query)) {
                $substring[] = $descriptor;
            }
        }

        return $exact !== [] ? $exact : $substring;
    }

    private function printHeader(ActionDescriptor $descriptor, string $env): void
    {
        $method = $descriptor->route->methods()[0] ?? 'GET';
        $middleware = array_values(array_map(
            static fn(mixed $entry): string => (string) $entry,
            $descriptor->route->gatherMiddleware(),
        ));

        $this->line("Route: {$method} " . $descriptor->route->uri());
        $this->line('  controller: ' . ($descriptor->controller?->getName() ?? '(closure)'));
        $this->line('  middleware: ' . implode(', ', $middleware));
        $this->line('  environment: ' . $env);
        $this->line('');
    }

    private function printSpecDecision(string $specName, InclusionDecision $decision): void
    {
        $this->line("{$specName}:");

        foreach ($decision->trace as $entry) {
            $mark = $entry->passed ? '✓' : '✗';
            $this->line("    {$mark} {$entry->stage} {$entry->name} — {$entry->reason}");
        }

        $this->line('    → ' . $decision->summary);
        $this->line('');
    }

    // endregion
}
