<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Support;

use OpenApi\Annotations\AbstractAnnotation;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;
use OpenApi\Pipeline;
use OpenApi\Processors\OperationId;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\AnnotationWalker;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use ReflectionClass;
use Throwable;

use function array_keys;
use function array_values;
use function class_exists;
use function is_array;
use function is_string;
use function ltrim;
use function property_exists;
use function Radiergummi\OpenApi\is_defined;

/**
 * Runs swagger-php's own analyzer over a set of source paths once, harvesting the hand-authored
 * `#[OA\Schema]` / `@OA\Schema` definitions and operation-level `@OA` annotations a host app
 * already wrote.
 *
 * The scan is lazy and memoised: the first lookup triggers a single `Generator::generate()` pass,
 * after which all queries are served from the built indexes. A failing or empty scan degrades to
 * empty indexes rather than throwing (Tier-0 graceful degradation).
 *
 * @internal
 */
final class AuthoredAnnotationScanner
{
    private bool $scanned = false;

    /**
     * Authored component schemas keyed by their authored schema name.
     *
     * @var array<string, Schema>
     */
    private array $schemasByName = [];

    /**
     * Authored component schemas keyed by their declaring class (FQCN, no leading slash).
     *
     * @var array<string, Schema>
     */
    private array $schemasByClass = [];

    /**
     * Authored operations keyed by "declaringClass::method".
     *
     * @var array<string, Operation>
     */
    private array $operationsByMethod = [];

    /**
     * @param list<string> $scanPaths Directories (or files) to scan for authored annotations.
     */
    public function __construct(
        private readonly array $scanPaths,
        private readonly LoggerInterface $logger,
    ) {}

    public function schemaForName(string $name): ?Schema
    {
        $this->scan();

        return $this->schemasByName[$name] ?? null;
    }

    public function schemaForClass(string $class): ?Schema
    {
        $this->scan();

        return $this->schemasByClass[ltrim($class, '\\')] ?? null;
    }

    public function operationForMethod(string $class, string $method): ?Operation
    {
        $this->scan();

        $exact = $this->operationsByMethod[$this->methodKey($class, $method)] ?? null;

        if ($exact !== null) {
            return $exact;
        }

        // The route points at a subclass while the annotation was indexed under the parent class
        // or trait that physically declares the method (swagger-php keys by `_context`). Walk the
        // route controller's ancestry and retry the lookup against each declaring type; first match
        // wins. The exact-match fast path above means the walk only runs on a genuine miss.
        foreach ($this->declaringAncestry($class, $method) as $ancestor) {
            $match = $this->operationsByMethod[$this->methodKey($ancestor, $method)] ?? null;

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Whether the authored component schema named `$componentName` is referenced (via `$ref`) by
     * any *other* authored annotation — another class's authored schema, or any authored operation.
     * The schema declared by `$excludingClass` (the candidate for removal) is itself excluded.
     *
     * The migration removal rule uses this as its safety check: a schema another surviving authored
     * annotation still points at must not be removed, or that reference would dangle.
     */
    public function isSchemaReferencedByOtherAuthored(string $componentName, string $excludingClass): bool
    {
        $this->scan();

        $target = ComponentReference::pointer($componentName);
        $ownSchema = $this->schemasByClass[ltrim($excludingClass, '\\')] ?? null;

        foreach ($this->schemasByName as $schema) {
            if ($schema !== $ownSchema && $this->referencesRef($schema, $target)) {
                return true;
            }
        }

        foreach ($this->operationsByMethod as $operation) {
            if ($this->referencesRef($operation, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the `$ref` string `$target` appears anywhere in the annotation's object tree.
     */
    private function referencesRef(AbstractAnnotation $node, string $target): bool
    {
        $found = false;

        AnnotationWalker::walk($node, static function (AbstractAnnotation $annotation) use ($target, &$found): void {
            if (property_exists($annotation, 'ref') && is_string($annotation->ref) && $annotation->ref === $target) {
                $found = true;
            }
        });

        return $found;
    }

    private function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        $document = $this->generate();
        $this->scanned = true;

        if ($document === null) {
            return;
        }

        $this->indexSchemas($document);
        $this->indexOperations($document);
    }

    private function generate(): ?OpenApi
    {
        try {
            return new Generator($this->logger)
                // Drop the operation-id synthesiser: the scanner captures only what the host app
                // actually authored, and a swagger-php-generated operation-id hash is not that. An
                // author-written `operationId` is left untouched (the processor only fills in missing
                // ones). This keeps the redundancy oracle from treating a synthesised id as authored
                // content, and keeps the harvester from injecting hash ids the user never wrote.
                ->withProcessorPipeline(static fn(Pipeline $pipeline): Pipeline => $pipeline->remove(OperationId::class))
                ->generate($this->scanPaths, validate: false);
        } catch (Throwable $exception) {
            $this->logger->warning(
                "Failed to scan for authored swagger-php annotations: {$exception->getMessage()}",
            );

            return null;
        }
    }

    private function indexSchemas(OpenApi $document): void
    {
        /** @noinspection NotOptimalIfConditionsInspection */
        if (
            !is_defined($document->components)
            || !is_array($document->components->schemas)
        ) {
            return;
        }

        foreach ($document->components->schemas as $schema) {
            if (!$schema instanceof Schema) {
                continue;
            }

            if (is_defined($schema->schema)) {
                $this->schemasByName[$schema->schema] = $schema;
            }

            $class = $this->declaringClass($schema);

            if ($class !== null) {
                $this->schemasByClass[$class] = $schema;
            }
        }
    }

    private function indexOperations(OpenApi $document): void
    {
        if (!is_array($document->paths)) {
            return;
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations() as $operation) {
                $class = $this->declaringClass($operation);
                $method = $operation->_context?->method;

                if ($class === null || $method === null) {
                    continue;
                }

                $this->operationsByMethod[$this->methodKey($class, $method)] = $operation;
            }
        }
    }

    /**
     * Resolve the fully qualified declaring type of an annotation to a leading-slash-free FQCN.
     *
     * An annotation written inside a trait carries `_context->trait` (with `_context->class` null),
     * so a trait-declared `@OA` operation is indexed under the trait's name — which the ancestry
     * walk in {@see operationForMethod()} then resolves for a controller that uses the trait.
     */
    private function declaringClass(Schema|Operation $annotation): ?string
    {
        $context = $annotation->_context;
        $fullyQualified = $context?->fullyQualifiedName($context->class ?? $context->trait);

        return $fullyQualified === null ? null : ltrim($fullyQualified, '\\');
    }

    /**
     * The route controller's parent classes and used traits (recursively) that declare `$method`,
     * nearest-first. swagger-php indexes an authored operation under the class or trait that
     * physically declares it; for an inherited handler the route's controller differs from that
     * declaring type, so resolving the match means retrying the lookup against each ancestor.
     *
     * @return list<string>
     */
    private function declaringAncestry(string $class, string $method): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $candidates = [];

        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if ($parent->hasMethod($method)) {
                $candidates[$parent->getName()] = true;
            }
        }

        foreach ($this->reachableTraits($reflection) as $trait) {
            if ($trait->hasMethod($method)) {
                $candidates[$trait->getName()] = true;
            }
        }

        return array_keys($candidates);
    }

    /**
     * Every trait reachable from `$class`: its own and its parents' traits, recursively into
     * trait-of-trait.
     *
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionClass<object>>
     */
    private function reachableTraits(ReflectionClass $class): array
    {
        $collected = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraits() as $trait) {
                $this->collectTraits($trait, $collected);
            }
        }

        return array_values($collected);
    }

    /**
     * @param ReflectionClass<object>                $trait
     * @param array<string, ReflectionClass<object>> $collected
     */
    private function collectTraits(ReflectionClass $trait, array &$collected): void
    {
        if (isset($collected[$trait->getName()])) {
            return;
        }

        $collected[$trait->getName()] = $trait;

        foreach ($trait->getTraits() as $nested) {
            $this->collectTraits($nested, $collected);
        }
    }

    private function methodKey(string $class, string $method): string
    {
        return sprintf('%s::%s', ltrim($class, '\\'), $method);
    }
}
