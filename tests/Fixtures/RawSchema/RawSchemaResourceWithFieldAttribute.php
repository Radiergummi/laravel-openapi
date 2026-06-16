<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Attributes\RawSchema;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

/**
 * A `JsonResource` carrying both a class-level `#[RawSchema]` and class-level `#[ResourceField]`
 * declarations. The raw schema replaces the inferred body, so the resource fields have no effect.
 */
#[RawSchema([
    'oneOf' => [
        ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ['type' => 'string'],
    ],
])]
#[ResourceField('id', type: 'integer')]
#[ResourceField('owner', type: 'string')]
class RawSchemaResourceWithFieldAttribute extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['ignored' => 'this would be inferred without #[RawSchema]'];
    }
}
