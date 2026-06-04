<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;
use ReflectionMethod;

use function ucfirst;

/**
 * Core request-schema resolver
 *
 * Builds the request body from method-level `#[RequestField]` attributes stacked on a controller
 * action — the escape hatch for actions that validate outside a FormRequest/Data class (e.g. in an
 * Action/service). Each `#[RequestField]` becomes one property; `required: true` fields populate the
 * schema's `required` list. Composes with `#[RequestBody]` for the media type. Registered ahead of
 * the FormRequest resolver, so an explicit author declaration wins over a type-hinted FormRequest.
 */
#[Scoped]
final readonly class RequestFieldRequestSchemaResolver implements RequestSchemaResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        $method = $descriptor->method;

        if ($method === null) {
            return null;
        }

        $attributes = $method->getAttributes(RequestField::class);

        if ($attributes === []) {
            return null;
        }

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($attributes as $attribute) {
            $field = $attribute->newInstance();

            if ($field->name === null || $field->name === '') {
                continue;
            }

            $property = new OA\Property(['property' => $field->name]);
            $field->descriptor()->applyTo($property);
            $properties[] = $property;

            if ($field->required === true) {
                $required[] = $field->name;
            }
        }

        if ($properties === []) {
            return null;
        }

        $schemaProperties = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schemaProperties['required'] = $required;
        }

        $key = $this->componentKey($method);
        $this->registry->registerNamed($key, new OA\Schema($schemaProperties));

        return new ResolvedSchema(
            componentKey: $key,
            mediaType: $this->mediaType($method),
        );
    }

    private function componentKey(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getShortName() . ucfirst($method->getName()) . 'Request';
    }

    private function mediaType(ReflectionMethod $method): MediaType
    {
        foreach ($method->getAttributes(RequestBody::class) as $attribute) {
            $mediaType = $attribute->newInstance()->mediaType;

            if ($mediaType !== null) {
                return $mediaType;
            }
        }

        return MediaType::Json;
    }
}
