<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimised reproduction of the AureusERP/Koel pattern: a resource returned from a controller
 * action that declares no return type. Pure-literal toArray() so the shape is fully inferable.
 */
class UntypedReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => 1,
            'title' => 'untyped',
        ];
    }
}
