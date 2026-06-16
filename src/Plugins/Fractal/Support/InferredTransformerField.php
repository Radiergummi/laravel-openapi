<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

use OpenApi\Annotations as OA;

/**
 * One field inferred from a transformer's `transform()` literal. All inferred fields are required.
 * Values the bounded reader refused to type are kept as unconstrained properties (dropping them
 * would be silently wrong); their key paths are collected in `$unconstrainedPaths` for the schema
 * builder to summarise.
 *
 * @internal
 */
final readonly class InferredTransformerField
{
    /**
     * @param list<string> $unconstrainedPaths
     */
    public function __construct(
        public string $name,
        public OA\Property $property,
        public array $unconstrainedPaths = [],
    ) {}
}
