<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

use OpenApi\Annotations as OA;

/**
 * One response field inferred from a transformer's `transform()` literal. Every inferred field
 * is required — `transform()` has no conditional-field idiom — but a value the bounded reader
 * refused to type keeps its key as an unconstrained property (dropping a response property
 * would be silently wrong) and is flagged so the schema builder can summarise the refusals.
 *
 * @internal
 */
final readonly class InferredTransformerField
{
    public function __construct(
        public string $name,
        public OA\Property $property,
        public bool $unconstrained = false,
    ) {}
}
