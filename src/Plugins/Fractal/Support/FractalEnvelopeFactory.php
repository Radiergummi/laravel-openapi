<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;

/**
 * Builds the response envelope schema for a Fractal-bound endpoint.
 *
 * Supports DataArray (default), ArraySerializer, and JsonApi serializers.
 * Custom serializers are not modelled; use `#[Response]` to declare their schema explicitly.
 */
#[Scoped]
final readonly class FractalEnvelopeFactory
{
    public function single(string $ref, Serializer $serializer = Serializer::DataArray): OA\Schema
    {
        return match ($serializer) {
            Serializer::DataArray => $this->dataArraySingle($ref),
            Serializer::ArraySerializer => $this->arraySerializerSingle($ref),
            Serializer::JsonApi => $this->jsonApiSingle($ref),
        };
    }

    private function dataArraySingle(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property(['property' => 'data', 'ref' => $ref]),
            ],
        ]);
    }

    private function arraySerializerSingle(string $ref): OA\Schema
    {
        return new OA\Schema(['ref' => $ref]);
    }

    private function jsonApiSingle(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'data',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'type', 'type' => 'string']),
                        new OA\Property(['property' => 'id', 'type' => 'string']),
                        new OA\Property(['property' => 'attributes', 'ref' => $ref]),
                    ],
                ]),
            ],
        ]);
    }

    public function collection(string $ref, Serializer $serializer = Serializer::DataArray): OA\Schema
    {
        return match ($serializer) {
            Serializer::DataArray => $this->dataArrayCollection($ref),
            Serializer::ArraySerializer => $this->arraySerializerCollection($ref),
            Serializer::JsonApi => $this->jsonApiCollection($ref),
        };
    }

    private function dataArrayCollection(string $ref): OA\Schema
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

    private function arraySerializerCollection(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'array',
            'items' => new OA\Items(['ref' => $ref]),
        ]);
    }

    private function jsonApiCollection(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'data',
                    'type' => 'array',
                    'items' => new OA\Items([
                        'type' => 'object',
                        'properties' => [
                            new OA\Property(['property' => 'type', 'type' => 'string']),
                            new OA\Property(['property' => 'id', 'type' => 'string']),
                            new OA\Property(['property' => 'attributes', 'ref' => $ref]),
                        ],
                    ]),
                ]),
            ],
        ]);
    }

    public function paginated(string $ref, Serializer $serializer = Serializer::DataArray): OA\Schema
    {
        return match ($serializer) {
            Serializer::DataArray, Serializer::ArraySerializer => $this->dataArrayPaginated($ref),
            Serializer::JsonApi => $this->jsonApiPaginated($ref),
        };
    }

    private function dataArrayPaginated(string $ref): OA\Schema
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

    private function jsonApiPaginated(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'data',
                    'type' => 'array',
                    'items' => new OA\Items([
                        'type' => 'object',
                        'properties' => [
                            new OA\Property(['property' => 'type', 'type' => 'string']),
                            new OA\Property(['property' => 'id', 'type' => 'string']),
                            new OA\Property(['property' => 'attributes', 'ref' => $ref]),
                        ],
                    ]),
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
                                new OA\Property(['property' => 'per-page', 'type' => 'integer']),
                                new OA\Property(['property' => 'current-page', 'type' => 'integer']),
                                new OA\Property(['property' => 'total-pages', 'type' => 'integer']),
                            ],
                        ]),
                    ],
                ]),
            ],
        ]);
    }
}
