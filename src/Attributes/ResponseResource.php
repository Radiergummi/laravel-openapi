<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Names the resource class an operation returns — used when the return type is too loose
 * (`JsonResponse`, `AnonymousResourceCollection`, the `JsonResource` base). `collection: null`
 * lets the resolver infer cardinality. Method-level wins over class-level on conflict.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS)]
final readonly class ResponseResource
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
        public ?bool $collection = null,
    ) {}
}
