<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use OpenApi\Annotations as OA;

/**
 * Builds the `data` / `data+links+meta` envelope Laravel serializes API
 * Resource responses into. The single shape is `{data}`; the collection shape
 * models the paginated `{data, links, meta}` form (the dominant convention).
 */
final readonly class ResourceEnvelopeFactory
{
    public function single(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property(['property' => 'data', 'ref' => $ref]),
            ],
        ]);
    }

    public function collection(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'data',
                    'type' => 'array',
                    'items' => new OA\Items(['ref' => $ref]),
                ]),
                new OA\Property([
                    'property' => 'links',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'first', 'type' => 'string']),
                        new OA\Property(['property' => 'last', 'type' => 'string']),
                        new OA\Property(['property' => 'prev', 'type' => 'string']),
                        new OA\Property(['property' => 'next', 'type' => 'string']),
                    ],
                ]),
                new OA\Property([
                    'property' => 'meta',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'current_page', 'type' => 'integer']),
                        new OA\Property(['property' => 'from', 'type' => 'integer']),
                        new OA\Property(['property' => 'last_page', 'type' => 'integer']),
                        new OA\Property(['property' => 'path', 'type' => 'string']),
                        new OA\Property(['property' => 'per_page', 'type' => 'integer']),
                        new OA\Property(['property' => 'to', 'type' => 'integer']),
                        new OA\Property(['property' => 'total', 'type' => 'integer']),
                    ],
                ]),
            ],
        ]);
    }
}
