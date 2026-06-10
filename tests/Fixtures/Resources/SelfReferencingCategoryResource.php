<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Self-referencing nested value — must terminate through the registry's cycle guard.
 */
class SelfReferencingCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => 'category',
            'parent' => new SelfReferencingCategoryResource($this->whenLoaded('parent')),
        ];
    }
}
