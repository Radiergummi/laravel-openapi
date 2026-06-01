<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;
use Radiergummi\OpenApi\Support\Routing\UriParameterDescriptor;
use Radiergummi\OpenApi\Support\Routing\WhereKind;
use ReflectionAttribute;
use ReflectionParameter;

use function array_map;
use function sprintf;

/**
 * Converts {@see UriParameterDescriptor}s into swagger-php path parameter annotations.
 *
 * Enriches each parameter with `description` and `example` from a `#[PathParam]` (or any
 * FieldAttribute subclass) declared on the corresponding ReflectionParameter.
 */
#[Scoped]
final readonly class UriParametersExtractor
{
    public function __construct(
        private JsonSchemaFromType $schemaFromType,
    ) {}

    /**
     * Builds swagger-php path parameter annotations from descriptor/parameter pairs.
     *
     * @param list<array{UriParameterDescriptor, ?ReflectionParameter}> $parameters Each pair is a
     *                                                                              descriptor and the
     *                                                                              ReflectionParameter it was resolved
     *                                                                              from (used to read FieldAttribute
     *                                                                              annotations); the parameter is null
     *                                                                              when none is available.
     *
     * @return list<OA\Parameter>
     */
    public function extract(array $parameters): array
    {
        return array_map(
            fn(array $pair): OA\Parameter => $this->buildParameter($pair[0], $pair[1]),
            $parameters,
        );
    }

    private function buildParameter(
        UriParameterDescriptor $descriptor,
        ?ReflectionParameter $reflectionParameter,
    ): OA\Parameter {
        $schema = $this->schemaFromType->fromType($descriptor->type);

        // Apply WhereKind overrides — the constraint semantics take precedence over what the type
        // alone would produce (e.g., a plain string type with a WhereUuid constraint should expose
        // `format: uuid`).
        $schema = $this->applyWhereKindOverrides($schema, $descriptor);

        $fieldDescriptor = $this->resolveFieldDescriptor($reflectionParameter);

        if ($fieldDescriptor?->example !== null) {
            $schema->example = $fieldDescriptor->example;
        }

        // OpenAPI 3.x §4.8.12.1: path parameters MUST have `required: true`. Laravel's `{param?}`
        // optional-segment signal is not expressible in OAS on a single operation; we preserve it
        // as a description suffix instead. A future release may expand `{param?}` into two
        // operations (long form with the segment, short form without) to describe both surfaces.
        $props = [
            'name' => $descriptor->name,
            'in' => 'path',
            'required' => true,
            'schema' => $schema,
        ];

        $description = $fieldDescriptor !== null
            ? ($fieldDescriptor->description ?? $this->buildDescription($descriptor))
            : $this->buildDescription($descriptor);

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
        match ($descriptor->whereKind) {
            WhereKind::Uuid => $schema->format = 'uuid',
            WhereKind::Number => $schema->type = 'integer',
            WhereKind::In => $descriptor->enumCases !== null
                ? ($schema->enum = $descriptor->enumCases)
                : null,
            WhereKind::Custom => $descriptor->whereConstraint !== null
                ? ($schema->pattern = $descriptor->whereConstraint)
                : null,
            null => null,
        };

        return $schema;
    }

    /**
     * Reads a FieldAttribute subclass (e.g. #[PathParam]) off the ReflectionParameter to obtain
     * description and example for the path parameter.
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
        if ($descriptor->modelClass !== null && $descriptor->routeKeyName !== null) {
            return sprintf(
                'Bound by %s of %s.',
                $descriptor->routeKeyName,
                $descriptor->modelClass,
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
