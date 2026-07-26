<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

/**
 * The resource an action returns: its class and cardinality. When no concrete resource is named
 * but the wrapped model is knowable, `modelClass` is set instead. A target with neither is
 * ambiguous: the collection item class cannot be derived.
 */
final class ResourceTarget
{
    public bool $isAmbiguous {
        get => $this->resourceClass === null && $this->modelClass === null;
    }

    /**
     * @param null|class-string $resourceClass
     * @param null|class-string $modelClass    Model wrapped by a base `JsonResource`; mutually
     *                                         exclusive with `resourceClass`.
     * @param bool              $paginated     Whether a collection carries the `{data, links, meta}`
     *                                         envelope or a plain `{data}` envelope.
     * @param null|int          $successStatus The 2xx status the action authored for this response.
     *                                         Only the `response()->json(<resource>, <status>)`
     *                                         unwrap ever sets it; every other path leaves it null,
     *                                         meaning the conventional status applies.
     */
    public function __construct(
        public readonly ?string $resourceClass,
        public readonly bool $isCollection,
        public readonly ?string $modelClass = null,
        public readonly bool $paginated = true,
        public readonly ?int $successStatus = null,
    ) {}
}
