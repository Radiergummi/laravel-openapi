<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Values\GenreSummaryValue;

/**
 * Shape (B): `$this->field` against a non-Model value object named by `@mixin`.
 *
 * @mixin GenreSummaryValue
 */
class MixinValueObjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId,
            'song_count' => $this->songCount,
            'mixed' => $this->mixedKey,
        ];
    }
}
