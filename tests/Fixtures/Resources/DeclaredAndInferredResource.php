<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * `#[ResourceField]` wins per field over the inferred property; inferred fields it
 * does not cover compose alongside.
 *
 * @mixin Article
 */
#[ResourceField('id', description: 'Declared identifier.', type: 'integer')]
class DeclaredAndInferredResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
