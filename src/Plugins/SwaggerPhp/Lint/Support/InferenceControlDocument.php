<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\SpecPipeline;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

use function is_array;
use function ltrim;
use function Radiergummi\OpenApi\is_defined;

/**
 * The redundancy oracle for the swagger-php migration rules: an inference-only generation of a
 * spec, built with the {@see HarvestAuthoredAnnotationsStage} excluded.
 *
 * Answering "would inference emit an equivalent node *without* this annotation?" requires running
 * the generator's own inference rather than re-deriving it inside a lint rule (which would
 * duplicate the generator and drift from it). The harvester is also destructive — it lets authored
 * responses overwrite inferred ones — so the inference-only baseline cannot be recovered by
 * subtracting harvested contributions from a single document; it must be generated separately.
 *
 * On top of the consolidated stage pipeline this is a clean one-stage exclusion: the only
 * difference from a normal run is the harvester. {@see ComponentSchemaRegistry} and
 * {@see ExampleFileLoader} are scoped and carry mutable per-run state, so the control run is given
 * fresh scoped state via {@see Container::forgetScopedInstances()} — otherwise a prior in-scope
 * generation's accumulated components would silently corrupt it. A throwaway
 * {@see FindingsCollector} absorbs the control run's generation findings so they never leak into
 * the lint result, and the caller's collector is restored afterward (mirroring
 * {@see \Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator}).
 *
 * The result is memoized per (spec, environment): the rule consults it once per authored
 * annotation, but the second full generation must run only once.
 *
 * @internal
 */
#[Scoped]
final class InferenceControlDocument
{
    /** @var array<string, OA\OpenApi> */
    private array $documents = [];

    /** @var array<string, array<class-string, OA\Schema>> */
    private array $schemasByClass = [];

    public function __construct(
        private readonly Container $container,
        private readonly SpecRegistry $registry,
        #[Config('app.env', 'production')]
        private readonly string $environment = 'production',
    ) {}

    /**
     * The inference-only document for the named spec, built once and memoized.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException   if the named spec is not defined
     */
    public function forSpec(string $specName, ?string $environment = null): OA\OpenApi
    {
        return $this->build($specName, $environment ?? $this->environment);
    }

    /**
     * The inference-only component schema for the given source class, or null when inference
     * produces no component schema for it. Matched by source class, not by name — the serialized
     * name is irrelevant to whether inference reproduces the class.
     *
     * @param class-string $class
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException   if the named spec is not defined
     */
    public function schemaForClass(string $specName, string $class, ?string $environment = null): ?OA\Schema
    {
        $resolvedEnvironment = $environment ?? $this->environment;
        $this->build($specName, $resolvedEnvironment);

        return $this->schemasByClass[$specName . '@' . $resolvedEnvironment][ltrim($class, '\\')] ?? null;
    }

    /**
     * Build (and memoize) the inference-only document and its class → schema index for the run.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException   if the named spec is not defined
     */
    private function build(string $specName, string $environment): OA\OpenApi
    {
        $key = $specName . '@' . $environment;

        if (isset($this->documents[$key])) {
            return $this->documents[$key];
        }

        $specification = $this->registry->get($specName);

        // Preserve the caller's findings collector across the scope reset, and swap in a throwaway
        // so the control run's generation findings are discarded rather than emitted into the
        // active lint run.
        $collector = $this->container->resolved(FindingsCollector::class)
            ? $this->container->make(FindingsCollector::class)
            : null;

        $this->container->forgetScopedInstances();
        $this->container->instance(FindingsCollector::class, new ArrayFindingsCollector());

        try {
            $document = $this->container->make(SpecPipeline::class)
                ->withoutStage(HarvestAuthoredAnnotationsStage::class)
                ->run($specification, $environment);

            // The pipeline repopulated the scoped registry during the run; re-resolve it to read
            // the class → component-name map this control produced.
            $this->schemasByClass[$key] = $this->indexSchemasByClass(
                $document,
                $this->container->make(ComponentSchemaRegistry::class)->componentClassMap(),
            );

            return $this->documents[$key] = $document;
        } finally {
            $this->container->forgetInstance(FindingsCollector::class);

            if ($collector !== null) {
                $this->container->instance(FindingsCollector::class, $collector);
            }
        }
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
