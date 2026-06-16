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
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function is_array;
use function Radiergummi\OpenApi\is_defined;

/**
 * Drives multi-spec generation. Resets scoped state between specs via
 * {@see Container::forgetScopedInstances()} so per-run state cannot leak.
 *
 * Used by {@see GenerateCommand} and {@see DocsController}.
 *
 * @internal
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
     * Resets scoped instances, then runs `$generate`. The {@see FindingsCollector} is captured
     * before the reset and restored after; when `$discardFindings` is true a throwaway collector
     * absorbs findings instead.
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
     * Generates the named spec with the given stages excluded. Used by the lint layer to determine
     * what inference alone would produce. Findings from this control run are discarded.
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

                // Re-resolve the registry: the pipeline repopulated it during the run.
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
