<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\InferenceOnlyGeneration;

use function is_array;
use function is_string;
use function ltrim;
use function Radiergummi\OpenApi\is_defined;
use function strtolower;

/**
 * Inference-only view used by migration rules to compare against authored annotations.
 *
 * Built once per spec by {@see LintRunner}; empty unless a rule implements
 * {@see \Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument}.
 *
 * @internal
 */
final readonly class InferenceView
{
    /**
     * @param array<class-string, OA\Schema> $schemasByClass
     * @param array<string, OA\Operation>    $operationsByKey
     * @param array<string, OA\Schema>       $schemasByName   Control components keyed by component name.
     */
    public function __construct(
        private array $schemasByClass = [],
        private array $operationsByKey = [],
        private array $schemasByName = [],
    ) {}

    /**
     * Builds the view from an inference-only generation.
     */
    public static function from(InferenceOnlyGeneration $generation): self
    {
        $operationsByKey = [];

        foreach (is_array($generation->document->paths) ? $generation->document->paths : [] as $pathItem) {
            if (!is_string($pathItem->path)) {
                continue;
            }

            foreach ($pathItem->operations() as $operation) {
                $operationsByKey[self::operationKey($operation->method, $pathItem->path)] = $operation;
            }
        }

        return new self($generation->schemasByClass, $operationsByKey, self::schemasByName($generation));
    }

    /**
     * The control document's component schemas keyed by component name (the `$ref` target name).
     *
     * @return array<string, OA\Schema>
     */
    private static function schemasByName(InferenceOnlyGeneration $generation): array
    {
        $components = $generation->document->components;

        if (!$components instanceof OA\Components || !is_array($components->schemas)) {
            return [];
        }

        $byName = [];

        foreach ($components->schemas as $schema) {
            if ($schema instanceof OA\Schema && is_defined($schema->schema) && is_string($schema->schema)) {
                $byName[$schema->schema] = $schema;
            }
        }

        return $byName;
    }

    /** Lookup key: lowercased method, space, URI without leading slash. */
    private static function operationKey(string $method, string $uri): string
    {
        return strtolower($method) . ' ' . ltrim($uri, '/');
    }

    /**
     * @param class-string $class
     */
    public function schemaForClass(string $class): ?OA\Schema
    {
        return $this->schemasByClass[ltrim($class, '\\')] ?? null;
    }

    /**
     * The inference-only operation for a route, or null.
     */
    public function operationForRoute(string $method, string $uri): ?OA\Operation
    {
        return $this->operationsByKey[self::operationKey($method, $uri)] ?? null;
    }

    /**
     * The control component schema with the given component name (a `$ref` target), or null.
     */
    public function schemaForName(string $name): ?OA\Schema
    {
        return $this->schemasByName[$name] ?? null;
    }
}
