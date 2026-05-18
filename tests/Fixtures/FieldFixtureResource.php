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
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * Fixture {@see ApiResource} subclass exercising the
 * {@see \Radiergummi\OpenApi\Core\Attributes\ResponseField} attribute across the documented
 * shape fields (type, format, description, enum).
 *
 * @extends ApiResource<object>
 */
final class FieldFixtureResource extends ApiResource
{
    public const string TYPE = 'fieldFixture';

    #[\Radiergummi\OpenApi\Core\Attributes\ResponseField(
        type: 'integer',
        description: 'Number of items in the bucket.',
        example: 7,
    )]
    public const string FIELD_COUNT = 'count';

    #[\Radiergummi\OpenApi\Core\Attributes\ResponseField(format: 'date-time')]
    public const string FIELD_CREATED_AT = 'createdAt';

    #[\Radiergummi\OpenApi\Core\Attributes\ResponseField(
        type: 'string',
        enum: ['draft', 'active', 'archived'],
        nullable: true,
    )]
    public const string FIELD_STATE = 'state';

    public const string FIELD_UNANNOTATED = 'unannotated';

    // --- Inference-only fields (no attribute) -------------------------------

    public const string FIELD_UPDATED_AT = 'updatedAt';

    public const string FIELD_FOUNDING_DATE = 'foundingDate';

    public const string FIELD_COMPANY_UUID = 'companyUuid';

    public const string FIELD_AVATAR_URL = 'avatarUrl';

    public const string FIELD_REDIRECT_URI = 'redirectUri';

    public const string FIELD_INVOICE_EMAIL = 'invoiceEmail';

    public const string FIELD_REQUIREMENTS_COUNT = 'requirementsCount';

    public const string FIELD_IS_ACTIVE = 'isActive';

    public const string FIELD_HAS_TRADE_DATA = 'hasTradeData';

    // Snake-case variants exercise the alternate regex branch.
    public const string FIELD_CREATED_AT_SNAKE = 'created_at';

    public const string FIELD_PROJECT_UUID_SNAKE = 'project_uuid';

    // Words that look like inference targets but should NOT match.
    public const string FIELD_FLAT = 'flat';

    public const string FIELD_ISLAND = 'island';

    // Explicit attribute must override inference (date-time would be inferred).
    #[\Radiergummi\OpenApi\Core\Attributes\ResponseField(type: 'integer', description: 'Override demo.')]
    public const string FIELD_OVERRIDDEN_AT = 'overriddenAt';

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }
}
