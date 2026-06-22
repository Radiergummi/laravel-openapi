<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Core\Support\SchemaFromFormRequest;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;

use function in_array;

/**
 * Builds the request body schema from a Laravel {@see FormRequest} type-hinted on the controller method.
 *
 * A FormRequest on a GET/HEAD action yields no request body: GET request bodies are discouraged by
 * the OpenAPI spec, and {@see CoreQueryParameterResolver} surfaces the same `rules()` as query
 * parameters instead. POST/PUT/PATCH keep the request body. A DELETE body never serializes anyway:
 * `OperationDescriptor::shouldAttachBody()` only attaches one on POST/PUT/PATCH.
 */
#[Scoped]
final readonly class FormRequestRequestSchemaResolver implements RequestSchemaResolver
{
    private const array BODYLESS_METHODS = [HttpMethod::Get, HttpMethod::Head];

    public function __construct(
        private SchemaFromFormRequest $schemaBuilder,
        private ComponentSchemaRegistry $registry,
        private PayloadParameterScanner $scanner,
    ) {}

    #[Override]
    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        if ($descriptor->method === null) {
            return null;
        }

        if (in_array($descriptor->httpMethod, self::BODYLESS_METHODS, true)) {
            return null;
        }

        $formRequestClass = $this->scanner->candidateOfType($descriptor->method, FormRequest::class);

        if ($formRequestClass === null) {
            return null;
        }

        // build() registers the schema as a side-effect.
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
