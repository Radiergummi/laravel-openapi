<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Closure;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Override;
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
use ReflectionAttribute;
use ReflectionMethod;

use function array_map;
use function array_unshift;
use function preg_replace;
use function ucfirst;

/**
 * Builds a discriminated (`oneOf` + `discriminator`) request body from
 * `#[RequestBody(discriminator: '…')]` plus `#[RequestVariant]`s.
 * Defers (returns null) when the method carries no discriminator.
 */
final readonly class DiscriminatedRequestSchemaResolver implements RequestSchemaResolver
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private Closure $refSchemaResolvers,
        private FindingsCollector $findings,
        private RequestFieldObjectBuilder $objectBuilder,
    ) {}

    /** @throws InvalidArgumentException */
    #[Override]
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
            static fn(ReflectionAttribute $attribute): RequestVariant => $attribute->newInstance(),
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

        $wrapperKey = $this->wrapperKey($method);

        // Pre-seed with the wrapper key to detect branch key collisions with the wrapper itself.
        $usedBranchKeys = [$wrapperKey => true];

        foreach ($variants as $variant) {
            if (isset($seen[$variant->value])) {
                $this->emit($descriptor, "duplicate #[RequestVariant] value '{$variant->value}'");

                continue;
            }

            $seen[$variant->value] = true;

            if ($variant->isMalformed()) {
                $this->emit(
                    $descriptor,
                    "#[RequestVariant] '{$variant->value}' must supply exactly one of schema/fields",
                );

                continue;
            }

            if ($variant->schema !== null) {
                $ref = $this->resolveClassRef($variant->schema);

                if ($ref === null) {
                    $this->emit(
                        $descriptor,
                        "#[RequestVariant] '{$variant->value}' schema '{$variant->schema}' is not resolvable to a component",
                    );

                    continue;
                }
            } else {
                $key = $this->branchKey($method, $variant->value);

                if (isset($usedBranchKeys[$key])) {
                    $this->emit(
                        $descriptor,
                        "#[RequestVariant] '{$variant->value}' maps to a component key that collides with another variant or the request-body wrapper; use a value that stays distinct after non-alphanumeric characters are removed",
                    );

                    continue;
                }

                $usedBranchKeys[$key] = true;
                $ref = $this->buildInlineBranch($discriminatorProperty, $variant, $key);
            }

            $oneOf[] = new OA\Schema(['ref' => $ref]);
            $mapping[$variant->value] = $ref;
        }

        if ($oneOf === []) {
            return null;
        }

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

    private function emit(ActionDescriptor $descriptor, string $message): void
    {
        $this->findings->emit(
            new Finding(
                ruleId: 'request.discriminator-malformed',
                level: 2,
                message: $message,
                location: FindingLocation::fromDescriptor($descriptor),
                fixHint: 'Give each #[RequestVariant] a unique value and exactly one of schema/fields.',
            ),
        );
    }

    private function wrapperKey(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getShortName() . ucfirst($method->getName()) . 'RequestBody';
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        foreach (($this->refSchemaResolvers)() as $resolver) {
            if ($resolver->canResolve($class)) {
                return $resolver->resolveRef($class);
            }
        }

        return null;
    }

    private function branchKey(ReflectionMethod $method, string $value): string
    {
        $suffix = ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return $method->getDeclaringClass()->getShortName() . ucfirst($method->getName()) . 'Request' . $suffix;
    }

    /**
     * @param non-empty-string $discriminatorProperty
     *
     * @throws InvalidArgumentException
     */
    private function buildInlineBranch(string $discriminatorProperty, RequestVariant $variant, string $key): string
    {
        $fields = $variant->fields;

        $hasDiscriminator = false;

        foreach ($fields as $field) {
            if ($field->name === $discriminatorProperty) {
                $hasDiscriminator = true;

                break;
            }
        }

        // Inject the discriminator property as a single-value enum unless the branch already declares it.
        if (!$hasDiscriminator) {
            array_unshift(
                $fields,
                new RequestField(
                    name: $discriminatorProperty,
                    required: true,
                    type: 'string',
                    enum: [$variant->value],
                ),
            );
        }

        [$properties, $required] = $this->objectBuilder->propertiesAndRequired($fields);

        $schemaProps = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schemaProps['required'] = $required;
        }

        $this->registry->registerNamed($key, new OA\Schema($schemaProps));

        return $this->registry->qualifyKey($key);
    }
}
