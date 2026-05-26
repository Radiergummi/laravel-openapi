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
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\Examples\FakerExampleSynthesiser;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;
use Throwable;

use function array_any;
use function array_key_exists;
use function class_basename;
use function is_string;
use function sprintf;

/**
 * Builds an {@see OA\Schema} for a {@see FormRequest} subclass by calling its `rules()` method
 * and mapping the result via {@see ValidationRulesToSchema}.
 *
 * The FormRequest is instantiated without the container (no DI, no auth, no route binding).
 * If instantiation or `rules()` throws, a placeholder schema is registered and a warning is
 * logged — one bad FormRequest must not abort the full generation run.
 *
 * FormRequests are flat — they do not contain other FormRequests — so the recursive cycle guard
 * inside {@see ComponentSchemaRegistry::buildOnce()} is a no-op in this caller. Using it anyway
 * keeps registration idempotent and consistent with the Data-class / API-Resource code paths.
 */
#[Scoped]
final readonly class SchemaFromFormRequest
{
    public function __construct(
        private ValidationRulesToSchema $rulesMapper,
        private ComponentSchemaRegistry $registry,
        private LoggerInterface $logger,
        private FakerExampleSynthesiser $synthesiser,
    ) {}

    /**
     * @param class-string<FormRequest> $formRequestClass
     */
    public function hasFileFields(string $formRequestClass): bool
    {
        // build() is idempotent (registry-cached) and always populates hasFileFields as a side
        // effect, so we delegate to it rather than duplicating the instantiation logic here.
        $this->build($formRequestClass);

        return $this->registry->getHasFileFields($formRequestClass) ?? false;
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     */
    public function build(string $formRequestClass): OA\Schema
    {
        $key = $this->registry->buildOnce(
            $formRequestClass,
            fn(): OA\Schema => $this->buildSchema($formRequestClass),
        );

        return new OA\Schema(['ref' => $this->registry->qualifyKey($key)]);
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     *
     * @throws ReflectionException
     */
    private function buildSchema(string $formRequestClass): OA\Schema
    {
        $basename = class_basename($formRequestClass);

        if (!method_exists($formRequestClass, 'rules')) {
            $this->registry->setHasFileFields($formRequestClass, false);

            return new OA\Schema([
                'type' => 'object',
                'description' => sprintf(
                    '%s does not declare a rules() method.',
                    $basename,
                ),
            ]);
        }

        try {
            $instance = new $formRequestClass();
            $rules = $instance->rules();
        } catch (Throwable $exception) {
            $this->logger->warning(
                sprintf(
                    'SchemaFromFormRequest failed for %s: %s',
                    $formRequestClass,
                    $exception->getMessage(),
                ),
            );

            $this->registry->setHasFileFields($formRequestClass, false);

            return new OA\Schema([
                'type' => 'object',
                'description' => sprintf(
                    'Schema introspection failed for %s.',
                    $basename,
                ),
            ]);
        }

        $result = $this->rulesMapper->process($rules, sourceClass: $formRequestClass);
        $fieldMap = $result['fields'];
        $itemsMap = $result['itemsFields'];
        $hasDotted = $result['hasDottedKeys'];

        $this->registry->setHasFileFields(
            $formRequestClass,
            array_any($fieldMap, static fn(FieldDescriptor $d): bool => $d->isFile),
        );

        $constantOverrides = $this->readConstantFieldAttributes($formRequestClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        /** @var array<string, OA\Property> $propertiesByName */
        $propertiesByName = [];

        foreach ($fieldMap as $fieldName => $descriptor) {
            $property = $this->buildProperty($fieldName, $descriptor);

            if (array_key_exists($fieldName, $constantOverrides)) {
                $constantOverrides[$fieldName]->descriptor()->applyTo($property);
            }

            // Lowest-priority fallback: synthesise an example when no authored source set one.
            // Use the property's effective format (which may have been overridden by a #[RequestField]
            // attribute) rather than the rules-derived descriptor format.
            if ($property->example === Generator::UNDEFINED) {
                if (!Generator::isDefault($property->format)) {
                    $descriptor->format = $property->format;
                }

                $synthesised = $this->synthesiser->synthesise($fieldName, $descriptor);

                if ($synthesised !== null) {
                    $property->example = $synthesised;
                }
            }

            $properties[] = $property;
            $propertiesByName[$fieldName] = $property;

            if ($descriptor->required) {
                $required[] = $fieldName;
            }
        }

        foreach ($itemsMap as $fieldName => $itemsDescriptor) {
            $prop = $propertiesByName[$fieldName] ?? null;

            if ($prop === null) {
                continue;
            }

            $items = new OA\Items([]);
            $itemsDescriptor->applyTo($items);

            // When the property is expressed as oneOf (nullable array wrapped by NullableSchema),
            // items must go onto the type:'array' inner schema — placing them on the outer wrapper
            // triggers swagger-php's "OA\Items() parent type must be array" check.
            if (is_array($prop->oneOf)) {
                foreach ($prop->oneOf as $branch) {
                    if ($branch instanceof OA\Schema && $branch->type === 'array') {
                        $branch->items = $items;

                        break;
                    }
                }
            } else {
                $prop->items = $items;
            }
        }

        $schemaProps = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schemaProps['required'] = $required;
        }

        if ($hasDotted) {
            $schemaProps['description'] = sprintf(
                '%s: some nested fields were skipped (dotted-key rules are not yet modelled).',
                $basename,
            );
        }

        return new OA\Schema($schemaProps);
    }

    /**
     * Reads `#[RequestField]` attributes from `PARAM_*` class constants on the FormRequest.
     * Allows authors to annotate constants:
     * ```php
     * #[RequestField(description: 'The target URL.', example: 'https://example.com')]
     * public const string PARAM_URL = 'url';
     * ```
     *
     * @param class-string<FormRequest> $formRequestClass
     *
     * @return array<string, FieldAttribute>
     *
     * @throws ReflectionException
     */
    private function readConstantFieldAttributes(string $formRequestClass): array
    {
        $reflection = new ReflectionClass($formRequestClass);
        $out = [];

        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $attrs = $constant->getAttributes(
                FieldAttribute::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            if ($attrs === []) {
                continue;
            }

            $fieldName = $constant->getValue();

            if (!is_string($fieldName)) {
                continue;
            }

            $out[$fieldName] = $attrs[0]->newInstance();
        }

        return $out;
    }

    private function buildProperty(string $name, FieldDescriptor $d): OA\Property
    {
        $property = new OA\Property(['property' => $name]);
        $d->applyTo($property);

        return $property;
    }
}
