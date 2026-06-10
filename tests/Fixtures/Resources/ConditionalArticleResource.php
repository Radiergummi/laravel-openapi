<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * The conditional-field idioms: when(), whenLoaded() (bare and resource-wrapped),
 * whenCounted(), merge(), and mergeWhen().
 *
 * @mixin Article
 */
class ConditionalArticleResource extends JsonResource
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subtitle' => $this->when($request->boolean('verbose'), $this->subtitle),
            'author' => new NestedAuthorResource($this->whenLoaded('author')),
            'editor' => $this->whenLoaded('editor'),
            'comments_count' => $this->whenCounted('comments'),
            $this->merge(['merged_always' => 'yes']),
            $this->mergeWhen($request->boolean('verbose'), ['merged_maybe' => 1]),
        ];
    }
}
