<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Closure;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\RequestVariant;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestFieldObjectBuilder;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;
use ReflectionMethod;

use function array_map;
use function array_unshift;
use function preg_replace;
use function ucfirst;

/**
 * Builds a discriminated (`oneOf` + `discriminator`) request body from a method-level
 * `#[RequestBody(discriminator: '…')]` plus repeatable `#[RequestVariant]`s. Each variant becomes
 * one branch — an inline object built from `#[RequestField]`s, or a class-string resolved through
 * the ref-resolver chain (Task 4). Registered ahead of the flat request-field/FormRequest
 * resolvers; defers (returns null) when the method carries no discriminator.
 */
final readonly class DiscriminatedRequestSchemaResolver implements RequestSchemaResolver
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy chain (built at first use,
     *                                                               consumed by the class-string branch path).
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        // @phpstan-ignore property.onlyWritten (reserved for class-string branch resolution, added in a follow-up task)
        private Closure $refSchemaResolvers,
        private FindingsCollector $findings,
    ) {}

    /** @throws InvalidArgumentException */
    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        $method = $descriptor->method;

        if ($method === null) {
            return null;
        }

        $requestBody = $this->readRequestBody($method);
        $discriminatorProperty = $requestBody?->discriminator;

        if ($discriminatorProperty === null || $discriminatorProperty === '') {
            return null;
        }

        $variants = array_map(
            static fn($attribute): RequestVariant => $attribute->newInstance(),
            $method->getAttributes(RequestVariant::class),
        );

        if ($variants === []) {
            $this->emit($descriptor, 'discriminator is set but no #[RequestVariant] is declared');

            return null;
        }

        /** @var list<OA\Schema> $oneOf */
        $oneOf = [];

        /** @var array<string, string> $mapping */
        $mapping = [];

        $seen = [];

        foreach ($variants as $variant) {
            if (isset($seen[$variant->value])) {
                $this->emit($descriptor, "duplicate #[RequestVariant] value '{$variant->value}'");

                continue;
            }

            $seen[$variant->value] = true;

            if ($variant->isMalformed()) {
                $this->emit($descriptor, "#[RequestVariant] '{$variant->value}' must supply exactly one of schema/fields");

                continue;
            }

            $ref = $this->buildInlineBranch($method, $discriminatorProperty, $variant);

            $oneOf[] = new OA\Schema(['ref' => $ref]);
            $mapping[$variant->value] = $ref;
        }

        if ($oneOf === []) {
            return null;
        }

        $wrapperKey = $this->wrapperKey($method);
        $this->registry->registerNamed($wrapperKey, new OA\Schema([
            'oneOf' => $oneOf,
            'discriminator' => new OA\Discriminator([
                'propertyName' => $discriminatorProperty,
                'mapping' => $mapping,
            ]),
        ]));

        return new ResolvedSchema(
            componentKey: $wrapperKey,
            mediaType: $requestBody->mediaType ?? MediaType::Json,
        );
    }

    /**
     * @param non-empty-string $discriminatorProperty
     *
     * @throws InvalidArgumentException
     */
    private function buildInlineBranch(ReflectionMethod $method, string $discriminatorProperty, RequestVariant $variant): string
    {
        $fields = $variant->fields;

        $hasDiscriminator = false;

        foreach ($fields as $field) {
            if ($field->name === $discriminatorProperty) {
                $hasDiscriminator = true;

                break;
            }
        }

        // Auto-inject the discriminator property as a single-value enum string — author override
        // wins, so only inject when the branch does not already declare it.
        if (!$hasDiscriminator) {
            array_unshift($fields, new RequestField(
                name: $discriminatorProperty,
                required: true,
                type: 'string',
                enum: [$variant->value],
            ));
        }

        [$properties, $required] = RequestFieldObjectBuilder::propertiesAndRequired($fields);

        $schemaProps = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schemaProps['required'] = $required;
        }

        $key = $this->branchKey($method, $variant->value);
        $this->registry->registerNamed($key, new OA\Schema($schemaProps));

        return "#/components/schemas/{$key}";
    }

    private function readRequestBody(ReflectionMethod $method): ?RequestBody
    {
        $attributes = $method->getAttributes(RequestBody::class);

        if ($attributes === []) {
            return null;
        }

        /** @var RequestBody $instance */
        $instance = $attributes[0]->newInstance();

        return $instance;
    }

    private function wrapperKey(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getShortName() . ucfirst($method->getName()) . 'RequestBody';
    }

    private function branchKey(ReflectionMethod $method, string $value): string
    {
        $suffix = ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return $method->getDeclaringClass()->getShortName() . ucfirst($method->getName()) . 'Request' . $suffix;
    }

    private function emit(ActionDescriptor $descriptor, string $message): void
    {
        $this->findings->emit(new Finding(
            ruleId: 'request.discriminator-malformed',
            level: 2,
            message: $message,
            location: new FindingLocation(
                file: $descriptor->method?->getFileName() ?: null,
                line: $descriptor->method?->getStartLine() ?: null,
                routeName: $descriptor->route->getName(),
                routeMethod: $descriptor->httpMethod,
                routeUri: $descriptor->route->uri(),
            ),
            fixHint: 'Give each #[RequestVariant] a unique value and exactly one of schema/fields.',
        ));
    }
}
