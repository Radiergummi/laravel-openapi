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
 * Fixture collection that declares its element resource via the `$collects`
 * property — the explicit signal `ResourceClassResolver` reads for collection
 * return types.
 *
 * @extends ApiResourceCollection<FieldFixtureResource>
 */
final class CollectingFixtureCollection extends ApiResourceCollection
{
    /** @var class-string<FieldFixtureResource> */
    public $collects = FieldFixtureResource::class;

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }
}
