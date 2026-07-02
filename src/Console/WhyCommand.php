<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\TagDeriver;
use Radiergummi\OpenApi\Support\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;
use Radiergummi\OpenApi\Support\Provenance\SchemaProvenance;
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
use function class_basename;
use function count;
use function implode;
use function str_contains;

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
        {--e|for-env= : Override the environment for Hide/Expose evaluation.}
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
        // --env is a reserved Laravel global; --for-env (short -e) overrides the environment for
        // #[Hide]/#[Expose] without changing APP_ENV.
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
     * @throws InvalidArgumentException
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

        // Building the operations above populates the component registry as a side effect, so its
        // provenance now covers the schemas this route references.
        $this->printComponentProvenance(app(ComponentSchemaRegistry::class)->allProvenance());
    }

    /**
     * Renders the producer (and any degraded/contested detail) behind each component schema this
     * route pulled into the registry.
     *
     * @param array<string, SchemaProvenance> $provenance
     *
     * @throws InvalidArgumentException
     */
    private function printComponentProvenance(array $provenance): void
    {
        if ($provenance === []) {
            return;
        }

        $this->line('');
        $this->line('Components:');

        foreach ($provenance as $key => $entry) {
            $detail = class_basename($entry->producer);

            if ($entry->degraded) {
                $detail .= ' (degraded' . ($entry->reason !== null ? ": {$entry->reason}" : '') . ')';
            } elseif ($entry->reason !== null) {
                $detail .= " ({$entry->reason})";
            }

            $this->components->twoColumnDetail($key, $detail);

            if ($entry->supersededBy !== []) {
                $this->components->bulletList(
                    array_map(
                        static fn(string $producer): string => 'superseded: ' . class_basename($producer),
                        $entry->supersededBy,
                    ),
                );
            }
        }
    }

    /**
     * @param list<FieldProvenance> $provenance
     *
     * @throws InvalidArgumentException
     */
    private function printProvenance(array $provenance): void
    {
        foreach ($provenance as $entry) {
            $this->components->twoColumnDetail(
                $entry->field,
                "{$entry->value}  {$entry->source} ({$entry->reason})",
            );

            if ($entry->supersededBy !== []) {
                $this->components->bulletList(
                    array_map(
                        static fn(string $candidate): string => "superseded: {$candidate}",
                        $entry->supersededBy,
                    ),
                );
            }
        }
    }

    // endregion
}
