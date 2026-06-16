<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

/**
 * The resource an action returns: the resource class and the response cardinality. A target may
 * instead name the Eloquent model a base `JsonResource` wraps (`modelClass`) when the body names
 * no concrete resource but the wrapped model is statically knowable — the response then documents
 * the model's schema. A target with neither class is *ambiguous* — the action returns a resource
 * collection type but nothing names the item class, so the shape cannot be derived.
 */
final class ResourceTarget
{
    public bool $isAmbiguous {
        get => $this->resourceClass === null && $this->modelClass === null;
    }

    /**
     * @param null|class-string $resourceClass
     * @param null|class-string $modelClass    Model wrapped by a base `JsonResource`, mutually
     *                                         exclusive with `resourceClass`.
     * @param bool              $paginated     Whether a collection response carries the paginated
     *                                         `{data, links, meta}` envelope (the default for
     *                                         signature- and attribute-located targets) or a plain
     *                                         `{data}` envelope. Only meaningful for collections.
     */
    public function __construct(
        public readonly ?string $resourceClass,
        public readonly bool $isCollection,
        public readonly ?string $modelClass = null,
        public readonly bool $paginated = true,
    ) {}
}
