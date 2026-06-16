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
 * Harvests hand-authored `#[OA\Schema]` / `@OA\Schema` definitions and operation-level `@OA`
 * annotations from a set of source paths via a single swagger-php pass.
 *
 * The scan is lazy and memoized; a failing scan degrades to empty indexes rather than throwing.
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
                // Drop the operation-id synthesiser so only truly authored operationIds are indexed,
                // not swagger-php-generated hashes.
                ->withProcessorPipeline(
                    static fn(Pipeline $pipeline): Pipeline => $pipeline->remove(OperationId::class),
                )
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

            $class = $this->declaringClassOf($schema);

            if ($class !== null) {
                $this->schemasByClass[$class] = $schema;
            }
        }
    }

    /**
     * Returns the leading-slash-free FQCN that physically declares the annotation.
     *
     * Trait-declared annotations carry `_context->trait` (not `_context->class`), so they are
     * indexed under the trait name; {@see operationForMethod()} resolves them via ancestry walk.
     */
    public function declaringClassOf(Schema|Operation $annotation): ?string
    {
        $context = $annotation->_context;
        $fullyQualified = $context->fullyQualifiedName($context->class ?? $context->trait);

        return $fullyQualified === null ? null : ltrim($fullyQualified, '\\');
    }

    private function indexOperations(OpenApi $document): void
    {
        if (!is_array($document->paths)) {
            return;
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations() as $operation) {
                $class = $this->declaringClassOf($operation);
                $method = $operation->_context->method;

                if ($class === null || $method === null) {
                    continue;
                }

                $this->operationsByMethod[$this->methodKey($class, $method)] = $operation;
            }
        }
    }

    private function methodKey(string $class, string $method): string
    {
        return sprintf('%s::%s', ltrim($class, '\\'), $method);
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

        // Walk ancestry when the annotation was indexed under a parent/trait rather than the
        // concrete controller class. The exact-match above ensures this runs only on a miss.
        foreach ($this->declaringAncestry($class, $method) as $ancestor) {
            $match = $this->operationsByMethod[$this->methodKey($ancestor, $method)] ?? null;

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Parent classes and used traits (recursively) that declare `$method`, nearest-first.
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
     * All traits reachable from `$class`, recursively including trait-of-trait.
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

    /**
     * Whether `$componentName` is referenced by any authored annotation other than the schema
     * declared by `$excludingClass`. Used as a dangling-ref guard before removal.
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
     * Whether `$target` appears as a `$ref` anywhere in the annotation's object tree.
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
}
