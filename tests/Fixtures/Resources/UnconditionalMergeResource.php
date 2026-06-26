<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * Writes an always-present element onto `$data` after the base literal. The base alone would
 * understate the value, so the reader refuses and falls back to the wrapped model schema.
 *
 * @mixin Article
 */
class UnconditionalMergeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id];
        $data['title'] = $this->title;

        return $data;
    }
}
