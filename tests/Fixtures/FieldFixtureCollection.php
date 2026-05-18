<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use App\Http\Resources\Base\ApiResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * Fixture collection resource used by OAPI-015 tests — verifies that
 * {@see \Radiergummi\OpenApi\Plugins\JsonApi\ResourceClassResolver::isCollectionEndpoint()}
 * detects collection endpoints via return type.
 *
 * @extends ApiResourceCollection<FieldFixtureResource>
 */
final class FieldFixtureCollection extends ApiResourceCollection
{
    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }
}
