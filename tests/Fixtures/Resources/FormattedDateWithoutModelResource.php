<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Refuse: no `@mixin`/`@extends` names a wrapped class, so there is no date evidence for the
 * `->format(…)` receiver.
 */
class FormattedDateWithoutModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'created_at' => $this->created_at->format(DATE_ATOM),
        ];
    }
}
