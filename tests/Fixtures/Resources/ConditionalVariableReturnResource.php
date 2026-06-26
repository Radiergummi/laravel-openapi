<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * Reassigns `$data` on a conditional path, so the variable is not a single unconditional literal;
 * the reader refuses and falls back to the wrapped model schema.
 *
 * @mixin Article
 */
class ConditionalVariableReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id];

        if ($request->boolean('verbose')) {
            $data = ['id' => $this->id, 'title' => $this->title];
        }

        return $data;
    }
}
