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
use Radiergummi\OpenApi\Core\Attributes\ResponseField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * Fixture resource for OAPI-013: conditional field and nested meta schema tests.
 *
 * @extends ApiResource<object>
 */
final class ConditionalFieldFixtureResource extends ApiResource
{
    public const string TYPE = 'conditionalFixture';

    /** Always present — must appear in required. */
    public const string FIELD_NAME = 'name';

    /** Conditionally present (loaded via whenLoaded) — must NOT appear in required. */
    #[ResponseField(conditional: true)]
    public const string FIELD_OWNER = 'owner';

    /**
     * OAPI-013: array-valued META_* constant — emitted as a nested object schema.
     * The key 'permissions' is derived from the constant name suffix.
     *
     * @var list<string>
     */
    public const array META_PERMISSIONS = ['addCollaborators', 'read', 'write'];

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }
}
