<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Envelopes;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * JSON:API errors document. Media type `application/vnd.api+json`. The shape is uniform
 * across every status code: `{ errors: [ { status, title, detail, source?: { pointer } } ] }`.
 *
 * Selected via `config('openapi.error_envelope') = 'json-api'`.
 */
final readonly class JsonApiEnvelope implements ErrorResponseResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
    {
        $this->registerSchema();

        $media = new OA\MediaType([
            'mediaType' => 'application/vnd.api+json',
            'schema' => new OA\Schema(['ref' => $this->registry->qualifyKey('ErrorDocument')]),
        ]);

        return new ErrorResponse(content: [$media]);
    }

    private function registerSchema(): void
    {
        if ($this->registry->hasKey('ErrorDocument')) {
            return;
        }

        $errorItem = new OA\Items([
            'type' => 'object',
            'properties' => [
                new OA\Property(['property' => 'status', 'type' => 'string']),
                new OA\Property(['property' => 'title', 'type' => 'string']),
                new OA\Property(['property' => 'detail', 'type' => 'string']),
                new OA\Property([
                    'property' => 'source',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'pointer', 'type' => 'string']),
                        new OA\Property(['property' => 'parameter', 'type' => 'string']),
                    ],
                ]),
            ],
        ]);

        $this->registry->registerNamed('ErrorDocument', new OA\Schema([
            'schema' => 'ErrorDocument',
            'type' => 'object',
            'description' => 'JSON:API errors document.',
            'required' => ['errors'],
            'properties' => [
                new OA\Property([
                    'property' => 'errors',
                    'type' => 'array',
                    'items' => $errorItem,
                ]),
            ],
        ]));
    }
}
