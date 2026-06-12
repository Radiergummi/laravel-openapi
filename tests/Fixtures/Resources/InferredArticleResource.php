<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * `$this->field` / `$this->resource->field` references resolving against the wrapped model.
 *
 * @mixin Article
 */
class InferredArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'published_at' => $this->published_at,
            'status' => $this->status,
            'reading_time' => $this->resource->reading_time,
            'internal_notes' => $this->internal_notes,
            'tags' => $this->tags,
            'created_at' => $this->created_at,
        ];
    }
}
