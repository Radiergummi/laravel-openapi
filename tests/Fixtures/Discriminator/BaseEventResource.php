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

/**
 * Polymorphic base ApiResource fixture for OAPI-027.
 *
 * @extends ApiResource<object>
 */
#[\Radiergummi\OpenApi\Core\Attributes\Discriminator(
    propertyName: 'type',
    mapping: [
        'message' => MessageEventResource::class,
        'status'  => StatusEventResource::class,
    ],
)]
abstract class BaseEventResource extends ApiResource
{
    public function toAttributes(Request $request): array
    {
        return [];
    }
}
