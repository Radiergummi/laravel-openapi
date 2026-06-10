<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

use function array_merge;

/**
 * A dynamic toArray() the bounded reader must refuse — degrades to the wrapped
 * model's schema (the `@mixin` resolves) plus a generation-log note.
 *
 * @mixin Article
 */
class DynamicToArrayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = ['id' => $this->id];

        return array_merge($base, ['inspected_at' => 'now']);
    }
}
