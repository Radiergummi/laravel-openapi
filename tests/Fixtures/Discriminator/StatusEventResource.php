<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Discriminator;

use App\Http\Resources\Base\ApiResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * Status-event variant for the OAPI-027 discriminator fixture.
 *
 * @extends ApiResource<object>
 */
final class StatusEventResource extends ApiResource
{
    public const string TYPE = 'status';

    public const string FIELD_CODE = 'code';

    public const string FIELD_CHANGED_AT = 'changedAt';

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }
}
