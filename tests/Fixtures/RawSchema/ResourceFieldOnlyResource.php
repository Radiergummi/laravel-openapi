<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

/**
 * A `JsonResource` carrying class-level `#[ResourceField]` declarations but no `#[RawSchema]`.
 * The conflict rule must stay silent: the resource fields are the intended source of truth.
 */
#[ResourceField('id', type: 'integer')]
class ResourceFieldOnlyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}
