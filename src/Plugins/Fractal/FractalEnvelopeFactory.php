<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use OpenApi\Annotations as OA;

/**
 * Builds the response envelope shapes Fractal's default `DataArraySerializer`
 * serializes into:
 *
 * - {@see single()}     — `{data}`
 * - {@see collection()} — `{data: [...]}`
 * - {@see paginated()}  — `{data: [...], meta: {pagination: {…}}}`,
 *                         matching `IlluminatePaginatorAdapter`.
 *
 * Other Fractal serializers (`JsonApiSerializer`, custom) produce different
 * shapes and are out of scope (see OAPI-052 in `docs/known-gaps.md`).
 */
final readonly class FractalEnvelopeFactory
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
            ],
        ]);
    }

    public function paginated(string $ref): OA\Schema
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
                    'property' => 'meta',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property([
                            'property' => 'pagination',
                            'type' => 'object',
                            'properties' => [
                                new OA\Property(['property' => 'total', 'type' => 'integer']),
                                new OA\Property(['property' => 'count', 'type' => 'integer']),
                                new OA\Property(['property' => 'per_page', 'type' => 'integer']),
                                new OA\Property(['property' => 'current_page', 'type' => 'integer']),
                                new OA\Property(['property' => 'total_pages', 'type' => 'integer']),
                                new OA\Property([
                                    'property' => 'links',
                                    'type' => 'object',
                                    'properties' => [
                                        new OA\Property(['property' => 'previous', 'type' => 'string']),
                                        new OA\Property(['property' => 'next', 'type' => 'string']),
                                    ],
                                ]),
                            ],
                        ]),
                    ],
                ]),
            ],
        ]);
    }
}
