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
 * The inference-only view the migration family compares authored annotations against: an
 * inference-only generation indexed for lookup by source class (schemas) and by "{method} {uri}"
 * (operations). Built once per spec by {@see LintRunner} and exposed on {@see LintContext}; empty
 * unless an active rule asked for it via {@see \Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument}.
 *
 * Owning both the index build and the lookups here keeps the "{method} {uri}" key convention in one
 * place, so the side that builds it and the side that reads it cannot drift.
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
     * Build the view from an inference-only generation, indexing its operations by "{method} {uri}".
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

    /**
     * The lookup key: the HTTP method lower-cased, a space, then the URI without a leading slash.
     */
    private static function operationKey(string $method, string $uri): string
    {
        return strtolower($method) . ' ' . ltrim($uri, '/');
    }

    /**
     * The inference-only component schema for a source class, or null when inference produced none
     * (the authored annotation is then load-bearing and must be kept).
     *
     * @param class-string $class
     */
    public function schemaForClass(string $class): ?OA\Schema
    {
        return $this->schemasByClass[ltrim($class, '\\')] ?? null;
    }

    /**
     * The inference-only operation for a route, or null when inference produced none.
     */
    public function operationForRoute(string $method, string $uri): ?OA\Operation
    {
        return $this->operationsByKey[self::operationKey($method, $uri)] ?? null;
    }
}
