<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use UnexpectedValueException;

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
class WhyCommand extends Command
{
    protected $signature = 'openapi:why
        {route : Route name (exact match) or URI substring.}
        {--for-env= : Override the environment for Hide/Expose evaluation.}';

    protected $description = 'Explain inclusion of a route across all defined specs';

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws UnexpectedValueException
     */
    public function handle(
        RouteIntrospector $introspector,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
    ): int {
        // --env is reserved by Laravel for the global "boot in environment X" switch, so we
        // cannot reuse it. --for-env carries the same intent: override the environment used for
        // #[Hide] / #[Expose] resolution without changing APP_ENV for the rest of the run.
        $query = (string) $this->argument('route');
        $env = (string) ($this->option('for-env') ?? app()->environment());

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
        $this->line(
            'Result: ' . ($included !== []
                ? 'included in [' . implode(', ', $included) . ']'
                : 'excluded from all specs'),
        );

        return self::SUCCESS;
    }

    // region Private helpers

    /**
     * @return list<ActionDescriptor>
     *
     * @throws ReflectionException
     * @throws UnexpectedValueException
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
        $method = $descriptor->httpMethod?->forDisplay() ?? '?';
        $middleware = array_values(
            array_map(
                static fn(mixed $entry): string => (string) $entry,
                app(RouteMiddlewareGatherer::class)->middlewareFor($descriptor->route),
            ),
        );

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
