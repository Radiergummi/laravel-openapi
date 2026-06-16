<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use OpenApi\Annotations as OA;

/**
 * Builds the envelope schemas for Laravel API Resource responses: `{data}` for single resources
 * and `{data, links, meta}` for paginated collections.
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

    /**
     * The `{data: [...]}` envelope for an unpaginated collection (no `links`/`meta`).
     */
    public function unpaginatedCollection(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'data',
                    'type' => 'array',
                    'items' => new OA\Items(['ref' => $ref]),
                ]),
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
