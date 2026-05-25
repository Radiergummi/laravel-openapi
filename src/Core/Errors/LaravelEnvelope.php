<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;

/**
 * Laravel's default JSON error envelope:
 *  - `{ message: string }` for generic 4xx/5xx responses.
 *  - `{ message: string, errors: { <field>: string[] } }` for ValidationException / 422.
 *
 * Media type: `application/json`. Selected via
 * `config('openapi.error_envelope') = 'laravel'`.
 */
final readonly class LaravelEnvelope implements ErrorResponseResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
    {
        $isValidation = $this->isValidation($descriptor);

        $this->registerSchemas();

        $schemaKey = $isValidation ? 'ValidationError' : 'Error';

        $media = new OA\MediaType([
            'mediaType' => 'application/json',
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
        if (!$this->registry->hasKey('Error')) {
            $this->registry->registerNamed('Error', new OA\Schema([
                'schema'     => 'Error',
                'type'       => 'object',
                'required'   => ['message'],
                'properties' => [
                    new OA\Property(['property' => 'message', 'type' => 'string']),
                ],
            ]));
        }

        if (!$this->registry->hasKey('ValidationError')) {
            $this->registry->registerNamed('ValidationError', new OA\Schema([
                'schema'     => 'ValidationError',
                'type'       => 'object',
                'required'   => ['message', 'errors'],
                'properties' => [
                    new OA\Property(['property' => 'message', 'type' => 'string']),
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
