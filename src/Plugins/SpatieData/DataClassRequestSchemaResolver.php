<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Core\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Spatie\LaravelData\Data;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;

/**
 * Spatie Data plugin request-schema resolver: builds the request body from a {@see Data} subclass
 * type-hinted on the controller method or discovered via an indirection wrapper (e.g. a Domain
 * Action). Indirection base classes are configured via `config/openapi.php`
 * (`request_payload_indirection`) and injected through {@see PayloadParameterScanner}.
 */
final readonly class DataClassRequestSchemaResolver implements RequestSchemaResolver
{
    public function __construct(
        private SchemaFromDataClass $schemaBuilder,
        private PayloadParameterScanner $scanner,
    ) {}

    /**
     * @throws UnsupportedException
     */
    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        if ($descriptor->method === null) {
            return null;
        }

        $dataClass = $this->scanner->candidateOfType($descriptor->method, Data::class);

        if ($dataClass === null) {
            return null;
        }

        $key = $this->schemaBuilder->build($dataClass);

        return new ResolvedSchema(
            componentKey: $key,
            mediaType: $this->schemaBuilder->hasFileProperties($dataClass)
                ? MediaType::MultipartFormData
                : MediaType::Json,
        );
    }
}
