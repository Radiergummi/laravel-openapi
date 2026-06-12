<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function strtoupper;

/**
 * Values the bounded reader refuses per key: method calls, ternaries, an unknown
 * `$this->field` (no `@mixin`). Keys stay, schemas stay unconstrained.
 */
class OpaqueValuesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'computed' => $this->computeLabel(),
            'either' => $request->boolean('flag') ? 'yes' : 'no',
            'unknown_field' => $this->some_field,
            'stable' => 'constant',
        ];
    }

    private function computeLabel(): string
    {
        return strtoupper('label');
    }
}
