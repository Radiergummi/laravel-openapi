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
 * Base resource whose discriminator mapping points to CyclicVariantResource,
 * which in turn points back here — forming a cross-reference cycle.
 *
 * @extends ApiResource<object>
 */
#[Discriminator(
    propertyName: 'kind',
    mapping: [
        'variant' => CyclicVariantResource::class,
    ],
)]
abstract class CyclicBaseResource extends ApiResource
{
    public const string TYPE = 'cyclic-base';

    public const string FIELD_NAME = 'name';

    public function toSelfLink(Request $request): MissingValue
    {
        return new MissingValue();
    }

    public function toAttributes(Request $request): array
    {
        return [];
    }
}
