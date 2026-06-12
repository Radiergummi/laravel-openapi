<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pure-literal toArray(): every key types from the literal alone, no model needed.
 */
class LiteralOnlyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'article',
            'version' => 2,
            'flags' => ['draft', 'internal'],
            'meta' => [
                'nested' => true,
            ],
        ];
    }
}
