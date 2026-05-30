<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Routing;

use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;

/**
 * Resolves the resource class an action returns and its response cardinality (singular vs.
 * collection). Implementations apply the conventions of their target resource convention
 * — e.g. the bundled implementation in {@see \Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator}
 * handles Eloquent `JsonResource` / `ResourceCollection` with `#[Collects]` + `$collects` +
 * `#[ResponseResource]` overrides.
 *
 * Plugin authors building response resolvers for alternative resource conventions
 * (JSON:API, HAL, Fractal, …) should consume this contract via the container so detection
 * stays consistent with the bundled behaviour and any future improvements flow through.
 */
interface ResourceTargetLocator
{
    /**
     * Locate the resource an action returns. Returns null when the action does not return a
     * resource (e.g. it returns a primitive, a `Data`, or a non-resource model).
     */
    public function locate(ActionDescriptor $descriptor): ?ResourceTarget;
}
