<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use App\Http\Resources\Base\ApiResource;
use Deprecated;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * Fixture resource for OAPI-031: deprecated FIELD_* constant detection.
 *
 * @extends ApiResource<object>
 */
final class DeprecatedFieldFixtureResource extends ApiResource
{
    public const string TYPE = 'deprecatedFixture';

    /** Current field — must NOT be deprecated in the schema. */
    public const string FIELD_ACTIVE = 'active';

    /**
     * @deprecated Use FIELD_ACTIVE instead.
     */
    #[Deprecated]
    public const string FIELD_LEGACY = 'legacy';

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }
}
