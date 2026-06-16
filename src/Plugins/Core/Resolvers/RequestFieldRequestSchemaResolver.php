<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestFieldObjectBuilder;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;
use ReflectionAttribute;
use ReflectionMethod;

use function array_map;
use function ucfirst;

/**
 * Builds the request body from `#[RequestField]` attributes on a controller action.
 *
 * Escape hatch for actions that validate outside a FormRequest/Data class. Registered before
 * the FormRequest resolver so explicit attribute declarations win.
 */
#[Scoped]
final readonly class RequestFieldRequestSchemaResolver implements RequestSchemaResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private RequestFieldObjectBuilder $objectBuilder,
    ) {}

    #[Override]
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

        $fields = array_map(
            /** @param ReflectionAttribute<RequestField> $attribute */
            static fn(ReflectionAttribute $attribute): RequestField => $attribute->newInstance(),
            $attributes,
        );

        [$properties, $required] = $this->objectBuilder->propertiesAndRequired($fields);

        if ($properties === []) {
            return null;
        }

        $schemaProperties = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schemaProperties['required'] = $required;
        }

        $key = $this->registry->reserveKey($this->syntheticName($method));
        $this->registry->registerNamed($key, new OA\Schema($schemaProperties));

        return new ResolvedSchema(
            componentKey: $key,
            mediaType: $this->mediaType($method),
        );
    }

    /**
     * Builds a synthetic `{Namespace}\{ControllerShortName}{Method}Request` name for
     * {@see ComponentSchemaRegistry::reserveKey()}.
     */
    private function syntheticName(ReflectionMethod $method): string
    {
        $declaringClass = $method->getDeclaringClass();
        $basename = $declaringClass->getShortName() . ucfirst($method->getName()) . 'Request';

        return $declaringClass->getNamespaceName() . '\\' . $basename;
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
