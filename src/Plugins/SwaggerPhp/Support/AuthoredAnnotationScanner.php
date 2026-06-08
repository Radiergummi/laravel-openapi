<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Support;

use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_array;
use function ltrim;
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

        return $this->operationsByMethod[$this->methodKey($class, $method)] ?? null;
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
     * Resolve the fully qualified declaring class of an annotation to a leading-slash-free FQCN.
     */
    private function declaringClass(Schema|Operation $annotation): ?string
    {
        $context = $annotation->_context;
        $fullyQualified = $context?->fullyQualifiedName($context->class);

        return $fullyQualified === null ? null : ltrim($fullyQualified, '\\');
    }

    private function methodKey(string $class, string $method): string
    {
        return sprintf('%s::%s', ltrim($class, '\\'), $method);
    }
}
