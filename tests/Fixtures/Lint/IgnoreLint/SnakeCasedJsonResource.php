<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Attributes\IgnoreLint;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

#[IgnoreLint('field.name-naming-inconsistent', reason: 'mirrors snake_case wire format')]
#[ResourceField('error_description', type: 'string')]
#[ResourceField('error_uri', type: 'string', conditional: true)]
final class SnakeCasedJsonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'error_description' => 'foo',
            'error_uri' => 'bar',
        ];
    }
}
