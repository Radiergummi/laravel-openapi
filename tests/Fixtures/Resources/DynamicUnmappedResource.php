<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function array_merge;

/**
 * A dynamic toArray() with no `@mixin` and no `#[ResourceField]` — nothing to infer
 * from, so the schema stays empty (today's behaviour) plus a generation-log note.
 */
class DynamicUnmappedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(['id' => 1], $request->all());
    }
}
