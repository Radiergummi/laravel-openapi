<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteModelBinding;
use Radiergummi\OpenApi\Support\Routing\UriParameterDescriptor;
use Radiergummi\OpenApi\Support\Routing\WhereKind;
use ReflectionAttribute;
use ReflectionParameter;

use function array_map;
use function Radiergummi\OpenApi\class_resource_name;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Converts {@see UriParameterDescriptor}s into swagger-php path parameter annotations.
 *
 * Enriches each parameter with `description` and `example` from a `#[PathParam]` (or any
 * FieldAttribute subclass) declared on the corresponding ReflectionParameter.
 *
 * @internal
 */
#[Scoped]
final readonly class UriParametersExtractor
{
    public function __construct(
        private JsonSchemaFromType $schemaFromType,
    ) {}

    /**
     * @param list<array{UriParameterDescriptor, ?ReflectionParameter}> $parameters        Descriptor/reflection pairs.
     *                                                                                     ReflectionParameter is null
     *                                                                                     when unavailable.
     * @param array<string, string>                                     $paramDescriptions Action `@param` description
     *                                                                                     text keyed by parameter name;
     *                                                                                     lowest-precedence fallback.
     *
     * @return list<OA\Parameter>
     */
    public function extract(array $parameters, array $paramDescriptions = []): array
    {
        return array_map(
            fn(array $pair): OA\Parameter => $this->buildParameter($pair[0], $pair[1], $paramDescriptions),
            $parameters,
        );
    }

    /**
     * @param array<string, string> $paramDescriptions
     */
    private function buildParameter(
        UriParameterDescriptor $descriptor,
        ?ReflectionParameter $reflectionParameter,
        array $paramDescriptions,
    ): OA\Parameter {
        $schema = $this->schemaFromType->fromType($descriptor->type);

        // WhereKind constraints take precedence over the bare type (e.g., a string with WhereUuid
        // gets `format: uuid`).
        $schema = $this->applyWhereKindOverrides($schema, $descriptor);

        $fieldDescriptor = $this->resolveFieldDescriptor($reflectionParameter);

        if ($fieldDescriptor?->example !== null) {
            $schema->example = $fieldDescriptor->example;
        }

        $fieldDescriptor?->applyAdditionalProperties($schema);
        $fieldDescriptor?->applyVendorExtensions($schema);

        // OAS 3.x §4.8.12.1: path parameters MUST have `required: true`. Laravel's `{param?}`
        // optional segments are not expressible in OAS on a single operation; preserved as a
        // description suffix instead.
        $props = [
            'name' => $descriptor->name,
            'in' => 'path',
            'required' => true,
            'schema' => $schema,
        ];

        // Lowest-precedence chain: #[PathParam] description ?? @param description ?? synthetic.
        $attributeDescription = $fieldDescriptor !== null ? $fieldDescriptor->description : null;
        $description = $attributeDescription
            ?? $paramDescriptions[$descriptor->name]
            ?? $this->buildDescription($descriptor);

        if ($descriptor->optional) {
            $note = 'Optional in URL — the segment may be omitted when calling this route.';
            $description = $description !== ''
                ? $description . ' ' . $note
                : $note;
        }

        if ($description !== '') {
            $props['description'] = $description;
        }

        return new OA\Parameter($props);
    }

    private function applyWhereKindOverrides(
        OA\Schema $schema,
        UriParameterDescriptor $descriptor,
    ): OA\Schema {
        // A backed-enum parameter resolves to a `$ref`; inline keywords alongside a `$ref` are
        // ignored in OAS 3.1.
        if (is_defined($schema->ref)) {
            return $schema;
        }

        // An explicit `where*` constraint wins; fall back to bound model key metadata only when
        // none is present.
        match ($descriptor->whereKind) {
            WhereKind::Uuid => $schema->format = 'uuid',
            WhereKind::Number => $schema->type = 'integer',
            WhereKind::In => $descriptor->enumCases !== null
                ? ($schema->enum = $descriptor->enumCases)
                : null,
            WhereKind::Custom => $descriptor->whereConstraint !== null
                ? ($schema->pattern = $descriptor->whereConstraint)
                : null,
            null => $this->applyModelBindingType($schema, $descriptor->modelBinding),
        };

        return $schema;
    }

    /**
     * Applies key type/format from binding metadata. A custom `{param:field}` or non-Eloquent
     * binding carries a null type and is a no-op.
     */
    private function applyModelBindingType(
        OA\Schema $schema,
        ?RouteModelBinding $binding,
    ): null {
        if ($binding?->type === null) {
            return null;
        }

        $schema->type = $binding->type;

        if ($binding->format !== null) {
            $schema->format = $binding->format;
        }

        return null;
    }

    /**
     * Reads a FieldAttribute subclass (e.g., `#[PathParam]`) off the parameter for description/example.
     */
    private function resolveFieldDescriptor(?ReflectionParameter $parameter): ?SchemaDescriptor
    {
        if ($parameter === null) {
            return null;
        }

        $source = $parameter->getAttributes(
            FieldAttribute::class,
            ReflectionAttribute::IS_INSTANCEOF,
        )[0] ?? null;

        return $source?->newInstance()->descriptor();
    }

    private function buildDescription(UriParameterDescriptor $descriptor): string
    {
        if ($descriptor->modelBinding !== null) {
            // Use a human-readable resource name; the FQCN should not leak into the spec.
            return sprintf(
                'Bound by %s of %s.',
                $descriptor->modelBinding->key,
                class_resource_name($descriptor->modelBinding->modelClass),
            );
        }

        if ($descriptor->whereConstraint !== null
            && $descriptor->whereKind === WhereKind::Custom
        ) {
            return sprintf('Constrained by regex: %s', $descriptor->whereConstraint);
        }

        return '';
    }
}
