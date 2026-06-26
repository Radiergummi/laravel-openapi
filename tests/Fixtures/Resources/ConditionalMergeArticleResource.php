<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * The Koel `SongResource` shape: a base `$data` literal, then a conditional `$data += [...]`
 * augmentation inside an `if`. The base literal resolves as a never-wrong subset; the
 * conditionally-added keys stay unread.
 *
 * @mixin Article
 */
class ConditionalMergeArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
        ];

        if ($request->boolean('verbose')) {
            $data += ['subtitle' => $this->subtitle];
        }

        return $data;
    }
}
