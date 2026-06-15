<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Attributes\RawSchema;

/**
 * An API Resource whose body is replaced by a literal `#[RawSchema]` carrying a composition
 * keyword (`oneOf`) the convention could never infer.
 */
#[RawSchema([
    'oneOf' => [
        ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ['type' => 'string'],
    ],
])]
class RawSchemaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['ignored' => 'this would be inferred without #[RawSchema]'];
    }
}
