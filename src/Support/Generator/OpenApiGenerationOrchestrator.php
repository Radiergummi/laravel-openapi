<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Http\DocsController;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function is_array;
use function Radiergummi\OpenApi\is_defined;

/**
 * Drives multi-spec generation in a single process.
 *
 * Per-run state in {@see ComponentSchemaRegistry} and {@see ExampleFileLoader} is reset between
 * specs by calling {@see Container::forgetScopedInstances()} and re-resolving
 * {@see OpenApiGenerator} fresh. This is the same scoped-rebinding pattern {@see LintRunner} uses
 * for its findings collector.
 *
 * Used by {@see GenerateCommand} and {@see DocsController}.
 */
#[Scoped]
final readonly class OpenApiGenerationOrchestrator
{
    public function __construct(
        private Container $container,
        private SpecRegistry $registry,
        #[Config('app.env', 'production')]
        private string $environment = 'production',
    ) {}

    /**
     * @throws BindingResolutionException
     * @throws InvalidArgumentException   if the named spec is not defined
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generateOne(string $name, ?string $environment = null): OA\OpenApi
    {
        return $this->generateForSpec(
            $this->registry->get($name),
            $environment ?? $this->environment,
        );
    }

    /**
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function generateForSpec(SpecDefinition $spec, string $environment): OA\OpenApi
    {
        return $this->withFreshScopedState(
            fn(): OA\OpenApi => $this->container->make(OpenApiGenerator::class)->generate($spec, $environment),
        );
    }

    /**
     * Run `$generate` against fresh scoped state: a {@see Container::forgetScopedInstances()} reset so
     * per-run state in {@see ComponentSchemaRegistry} / {@see ExampleFileLoader} can't leak across
     * generations.
     *
     * That reset also wipes any explicit {@see FindingsCollector} the caller pinned (e.g. LintRunner
     * binds an {@see ArrayFindingsCollector} to capture extractor-emitted findings), so it is captured
     * and restored. With `$discardFindings`, a throwaway collector receives the run's findings instead
     * — for control runs whose findings must not reach the caller.
     *
     * @template T
     *
     * @param callable(): T $generate
     *
     * @return T
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function withFreshScopedState(callable $generate, bool $discardFindings = false): mixed
    {
        $collector = $this->container->resolved(FindingsCollector::class)
            ? $this->container->make(FindingsCollector::class)
            : null;

        $this->container->forgetScopedInstances();

        $active = $discardFindings ? new ArrayFindingsCollector() : $collector;

        if ($active !== null) {
            $this->container->instance(FindingsCollector::class, $active);
        }

        try {
            return $generate();
        } finally {
            if ($discardFindings) {
                $this->container->forgetInstance(FindingsCollector::class);
            }

            if ($collector !== null) {
                $this->container->instance(FindingsCollector::class, $collector);
            }
        }
    }

    /**
     * @return array<string, OA\OpenApi>
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generateAll(?string $environment = null): array
    {
        $documents = [];

        foreach ($this->registry->all() as $spec) {
            $documents[$spec->name] = $this->generateForSpec(
                $spec,
                $environment ?? $this->environment,
            );
        }

        return $documents;
    }

    /**
     * Generate the named spec with the given stages excluded, yielding the resulting document and
     * its source-class → component-schema index.
     *
     * This is the "what would inference produce without these contributions?" oracle the lint layer
     * uses to decide annotation redundancy. It runs the pipeline directly (no lifecycle events — a
     * control run is not a real generation) and discards its generation findings through a throwaway
     * collector ({@see withFreshScopedState()}).
     *
     * @param list<class-string<SpecStage>> $excludedStages
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException   if the named spec is not defined
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function inferenceOnly(
        string $specName,
        array $excludedStages,
        ?string $environment = null,
    ): InferenceOnlyGeneration {
        $spec = $this->registry->get($specName);

        return $this->withFreshScopedState(
            function () use ($spec, $excludedStages, $environment): InferenceOnlyGeneration {
                $document = $this->container
                    ->make(SpecPipeline::class)
                    ->withoutStage(...$excludedStages)
                    ->run($spec, $environment ?? $this->environment);

                // The pipeline repopulated the scoped registry during the run; re-resolve it to read
                // the class → component-name map this control produced.
                $schemasByClass = $this->indexSchemasByClass(
                    $document,
                    $this->container->make(ComponentSchemaRegistry::class)->componentClassMap(),
                );

                return new InferenceOnlyGeneration($document, $schemasByClass);
            },
            discardFindings: true,
        );
    }

    /**
     * Invert the component-name → class map and resolve each to its schema in the document.
     *
     * @param array<string, class-string> $classMap component name → source class
     *
     * @return array<class-string, OA\Schema>
     */
    private function indexSchemasByClass(OA\OpenApi $document, array $classMap): array
    {
        if (!$document->components instanceof OA\Components || !is_array($document->components->schemas)) {
            return [];
        }

        $byName = [];

        foreach ($document->components->schemas as $schema) {
            if (is_defined($schema->schema)) {
                $byName[$schema->schema] = $schema;
            }
        }

        $byClass = [];

        foreach ($classMap as $name => $class) {
            if (isset($byName[$name])) {
                $byClass[$class] = $byName[$name];
            }
        }

        return $byClass;
    }
}
