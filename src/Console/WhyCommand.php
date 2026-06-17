<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\TagDeriver;
use Radiergummi\OpenApi\Support\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function app;
use function array_map;
use function array_values;
use function count;
use function implode;
use function max;
use function str_contains;
use function str_pad;
use function strlen;

/**
 * Explains whether and why a given route is included or excluded across all defined specs.
 *
 * Accepts a route name (exact match) or a URI substring. If the substring matches multiple
 * routes, the command lists the candidates and exits non-zero.
 */
class WhyCommand extends Command
{
    protected $signature = 'openapi:why
        {route : Route name (exact match) or URI substring.}
        {--for-env= : Override the environment for Hide/Expose evaluation.}
        {--fields : Also build the operation and explain how each derived field got its value.}';

    protected $description = 'Explain inclusion of a route across all defined specs';

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function handle(
        RouteIntrospector $introspector,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
    ): int {
        // --env is reserved by Laravel; --for-env overrides the environment for #[Hide]/#[Expose] without changing APP_ENV.
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

        if ($this->option('fields')) {
            $this->printFields($descriptor);
        }

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

    /**
     * Builds the operation per emitted verb (mirroring the paths stage) and prints the source
     * and reason behind each derived field.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function printFields(ActionDescriptor $descriptor): void
    {
        $builder = app(OperationBuilder::class);
        $tag = (new TagDeriver())->derive($descriptor);

        $verbs = array_values(array_filter(
            array_map(
                static fn(string $routeMethod): ?HttpMethod => HttpMethod::fromString($routeMethod),
                $descriptor->route->methods(),
            ),
            static fn(?HttpMethod $method): bool => $method !== null && $method !== HttpMethod::Head,
        ));

        $labelVerb = count($verbs) > 1;

        foreach ($verbs as $verb) {
            $operation = $builder->build($descriptor->withHttpMethod($verb), [$tag]);

            $this->line('');
            $this->line($labelVerb ? "Fields ({$verb->forDisplay()}):" : 'Fields:');

            if ($operation->provenance === []) {
                $this->line('    (no derived fields)');

                continue;
            }

            $this->printProvenance($operation->provenance);
        }
    }

    /**
     * @param list<FieldProvenance> $provenance
     */
    private function printProvenance(array $provenance): void
    {
        $labelWidth = 0;

        foreach ($provenance as $entry) {
            $labelWidth = max($labelWidth, strlen($entry->field) + 1);
        }

        foreach ($provenance as $entry) {
            $label = str_pad($entry->field . ':', $labelWidth + 1);
            $this->line("    {$label} {$entry->value} ← {$entry->source} ({$entry->reason})");

            foreach ($entry->supersededBy as $candidate) {
                $this->line(str_pad('', $labelWidth + 6) . "(superseded: {$candidate})");
            }
        }
    }

    // endregion
}
