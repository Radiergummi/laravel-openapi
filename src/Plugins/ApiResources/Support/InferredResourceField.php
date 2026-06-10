<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;

/**
 * One response field inferred from a Resource's `toArray()` literal: either a concrete
 * property schema, or a reference to a nested Resource class the schema builder resolves
 * (and registers) itself — the reader never touches the component registry.
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

    /**
     * A key whose value the bounded reader refused to type: the property is kept with an
     * unconstrained schema — dropping a response property would be silently wrong.
     */
    public static function ofUnconstrained(string $name, bool $required, OA\Property $property): self
    {
        return new self($name, $required, $property, resourceClass: null, isCollection: false, unconstrained: true);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     */
    public static function ofNestedResource(string $name, bool $required, string $resourceClass, bool $isCollection): self
    {
        return new self($name, $required, property: null, resourceClass: $resourceClass, isCollection: $isCollection, unconstrained: false);
    }
}
