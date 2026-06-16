<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\InferenceOnlyGeneration;

use function is_array;
use function is_string;
use function ltrim;
use function strtolower;

/**
 * Inference-only view used by migration rules to compare against authored annotations.
 *
 * Indexes an inference-only generation by source class (schemas) and by "{method} {uri}"
 * (operations). Built once per spec by {@see LintRunner}; empty unless a rule implements
 * {@see \Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument}.
 *
 * @internal
 */
final readonly class InferenceView
{
    /**
     * @param array<class-string, OA\Schema> $schemasByClass
     * @param array<string, OA\Operation>    $operationsByKey
     */
    public function __construct(
        private array $schemasByClass = [],
        private array $operationsByKey = [],
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

        return new self($generation->schemasByClass, $operationsByKey);
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
}
