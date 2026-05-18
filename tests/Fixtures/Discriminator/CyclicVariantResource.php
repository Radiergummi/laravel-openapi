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
use Radiergummi\OpenApi\Core\Attributes\Discriminator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * Variant resource that itself carries a #[Discriminator] pointing back to
 * CyclicBaseResource, creating a cross-reference cycle for the cycle-guard test.
 *
 * @extends ApiResource<object>
 */
#[Discriminator(
    propertyName: 'kind',
    mapping: [
        'base' => CyclicBaseResource::class,
    ],
)]
final class CyclicVariantResource extends ApiResource
{
    public const string TYPE = 'cyclic-variant';

    public const string FIELD_LABEL = 'label';

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }

    public function toAttributes(Request $request): array
    {
        return [];
    }
}
