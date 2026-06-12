<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * Nested resource values: `new X(...)`, `X::make(...)`, and `X::collection(...)`.
 *
 * @mixin Article
 */
class NestingArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => new NestedAuthorResource($this->author),
            'editor' => NestedAuthorResource::make($this->editor),
            'reviewers' => NestedAuthorResource::collection($this->author),
        ];
    }
}
