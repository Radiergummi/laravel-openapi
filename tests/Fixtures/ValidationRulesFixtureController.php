<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Fixture controller used by {@see DataValidationRulesTest} to expose Data classes as POST request
 * bodies so the OpenAPI generator can extract them via reflection (closures don't work with
 * ReflectionMethod-based extraction).
 */
class ValidationRulesFixtureController extends Controller
{
    public function store(ValidationRulesFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }

    public function storeThrowingRules(ThrowingRulesFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }

    public function storeTags(TagsWithItemsFixtureData $data): JsonResponse
    {
        return new JsonResponse();
    }
}
