<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * `$this->unless()` as the inverse of `$this->when()`, for both a scalar field and a nested
 * resource argument.
 *
 * @mixin Article
 */
class UnlessArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subtitle' => $this->when($request->boolean('verbose'), $this->subtitle),
            'internal_notes' => $this->unless($request->boolean('public'), $this->internal_notes),
            'editor' => new NestedAuthorResource($this->unless($request->boolean('public'), $this->editor)),
        ];
    }
}
