<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;

/**
 * Sits at the conventional `…\Http\Resources\{Model}Resource` location for the
 * `…\Models\Author` fixture model, so a bare `$author->toResource()` resolves here
 * through Laravel's own `guessResourceName()` convention.
 *
 * @mixin Author
 */
class AuthorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
