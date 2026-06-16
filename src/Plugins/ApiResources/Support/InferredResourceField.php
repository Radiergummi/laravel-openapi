<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;

/**
 * One response field inferred from a Resource's `toArray()` literal: a concrete property schema
 * or a reference to a nested Resource class resolved by the schema builder.
 *
 * @internal
 */
final readonly class InferredResourceField
{
    /**
     * @param null|class-string<JsonResource> $resourceClass
     */
    private function __construct(
        public string $name,
        public bool $required,
        public ?OA\Property $property,
        public ?string $resourceClass,
        public bool $isCollection,
        public bool $unconstrained,
    ) {}

    public static function ofProperty(string $name, bool $required, OA\Property $property): self
    {
        return new self($name, $required, $property, resourceClass: null, isCollection: false, unconstrained: false);
    }

    /** A key the bounded reader could not type; kept as unconstrained rather than silently dropped. */
    public static function ofUnconstrained(string $name, bool $required, OA\Property $property): self
    {
        return new self($name, $required, $property, resourceClass: null, isCollection: false, unconstrained: true);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     */
    public static function ofNestedResource(
        string $name,
        bool $required,
        string $resourceClass,
        bool $isCollection,
    ): self {
        return new self(
            $name,
            $required,
            property: null,
            resourceClass: $resourceClass,
            isCollection: $isCollection,
            unconstrained: false,
        );
    }
}
