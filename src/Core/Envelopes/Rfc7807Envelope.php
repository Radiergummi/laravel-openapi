<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Envelopes;

use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * RFC 7807 problem-details envelope. Media type `application/problem+json`.
 *
 * Selected via `config('openapi.error_envelope') = 'rfc7807'`. Registers two schemas:
 * `Problem` (generic) and `ValidationProblem` (adds the `errors` extension field).
 */
final readonly class Rfc7807Envelope implements ErrorResponseResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
    {
        $isValidation = $this->isValidation($descriptor);

        $this->registerSchemas();

        $schemaKey = $isValidation ? 'ValidationProblem' : 'Problem';

        $media = new OA\MediaType([
            'mediaType' => 'application/problem+json',
            'schema'    => new OA\Schema(['ref' => $this->registry->qualifyKey($schemaKey)]),
        ]);

        return new ErrorResponse(content: [$media]);
    }

    private function isValidation(ErrorDescriptor $descriptor): bool
    {
        if ($descriptor->exceptionClass !== null
            && is_a($descriptor->exceptionClass, ValidationException::class, true)
        ) {
            return true;
        }

        return $descriptor->exceptionClass === null && $descriptor->status === 422;
    }

    private function registerSchemas(): void
    {
        if (!$this->registry->hasKey('Problem')) {
            $this->registry->registerNamed('Problem', new OA\Schema([
                'schema'      => 'Problem',
                'type'        => 'object',
                'description' => 'RFC 7807 problem details object.',
                'properties'  => [
                    new OA\Property(['property' => 'type', 'type' => 'string', 'format' => 'uri']),
                    new OA\Property(['property' => 'title', 'type' => 'string']),
                    new OA\Property(['property' => 'status', 'type' => 'integer']),
                    new OA\Property(['property' => 'detail', 'type' => 'string']),
                    new OA\Property(['property' => 'instance', 'type' => 'string', 'format' => 'uri']),
                ],
            ]));
        }

        if (!$this->registry->hasKey('ValidationProblem')) {
            $this->registry->registerNamed('ValidationProblem', new OA\Schema([
                'schema'      => 'ValidationProblem',
                'type'        => 'object',
                'description' => 'RFC 7807 problem details with a per-field errors extension.',
                'properties'  => [
                    new OA\Property(['property' => 'type', 'type' => 'string', 'format' => 'uri']),
                    new OA\Property(['property' => 'title', 'type' => 'string']),
                    new OA\Property(['property' => 'status', 'type' => 'integer']),
                    new OA\Property(['property' => 'detail', 'type' => 'string']),
                    new OA\Property(['property' => 'instance', 'type' => 'string', 'format' => 'uri']),
                    new OA\Property([
                        'property'             => 'errors',
                        'type'                 => 'object',
                        'additionalProperties' => new OA\AdditionalProperties([
                            'type'  => 'array',
                            'items' => new OA\Items(['type' => 'string']),
                        ]),
                    ]),
                ],
            ]));
        }
    }
}
