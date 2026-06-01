<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

/**
 * The resource an action returns: the resource class and the response cardinality. A null
 * `resourceClass` marks an *ambiguous* endpoint — it returns a resource collection type but no
 * `#[ResponseResource]` names the item class, so the shape cannot be derived.
 */
final readonly class ResourceTarget
{
    /**
     * @param null|class-string $resourceClass
     */
    public function __construct(
        public ?string $resourceClass,
        public bool $isCollection,
    ) {}

    public function isAmbiguous(): bool
    {
        return $this->resourceClass === null;
    }
}
