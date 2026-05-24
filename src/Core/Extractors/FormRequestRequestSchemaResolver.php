<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Core\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * Core request-schema resolver
 *
 * Builds the request body from a Laravel {@see FormRequest} type-hinted on the controller method.
 */
#[Scoped]
final readonly class FormRequestRequestSchemaResolver implements RequestSchemaResolver
{
    public function __construct(
        private SchemaFromFormRequest $schemaBuilder,
        private ComponentSchemaRegistry $registry,
        private PayloadParameterScanner $scanner,
    ) {}

    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        if ($descriptor->method === null) {
            return null;
        }

        $formRequestClass = $this->scanner->candidateOfType($descriptor->method, FormRequest::class);

        if ($formRequestClass === null) {
            return null;
        }

        // build() returns an OA\Schema $ref, not the component key itself. We call it for its
        // registry side-effect, then retrieve the key separately via keyFor().
        $this->schemaBuilder->build($formRequestClass);

        $key = $this->registry->keyFor($formRequestClass);

        if ($key === null) {
            return null;
        }

        return new ResolvedSchema(
            componentKey: $key,
            mediaType: $this->schemaBuilder->hasFileFields($formRequestClass)
                ? MediaType::MultipartFormData
                : MediaType::Json,
        );
    }
}
