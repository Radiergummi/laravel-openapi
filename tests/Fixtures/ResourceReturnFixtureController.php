<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fixture controller — one method per response return-type shape that
 * `ResourceClassResolver` must classify. Method bodies are never executed.
 *
 * @internal
 */
class ResourceReturnFixtureController
{
    public function returnsResource(): FieldFixtureResource
    {
        return new FieldFixtureResource((object) []);
    }

    public function returnsCollection(): CollectingFixtureCollection
    {
        return new CollectingFixtureCollection([]);
    }

    public function returnsCollectionWithoutCollects(): FieldFixtureCollection
    {
        return new FieldFixtureCollection([]);
    }

    public function returnsJsonResource(): JsonResource
    {
        return new FieldFixtureResource((object) []);
    }

    public function returnsJsonResponse(): JsonResponse
    {
        return new JsonResponse();
    }

    public function returnsAnonymousCollection(): AnonymousResourceCollection
    {
        return FieldFixtureResource::collection([]);
    }

    #[ResponseResource(FieldFixtureResource::class)]
    public function annotatedJsonResponse(): JsonResponse
    {
        return new JsonResponse();
    }

    public function returnsVoid(): void {}
}
