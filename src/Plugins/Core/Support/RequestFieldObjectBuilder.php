<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Closure;
use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Support\Extraction\FieldReferenceProperty;

use function class_exists;

/**
 * Turns a list of `#[RequestField]`s into the `properties` and `required` parts of an object schema.
 *
 * A class-string `type:` resolves to a `$ref` via the ref-resolver chain; a class-string `items:`
 * on `type: 'array'` resolves to `items: { $ref }`. Both degrade to a permissive object on miss.
 *
 * @internal
 */
#[Scoped]
final readonly class RequestFieldObjectBuilder
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(
        private Closure $refSchemaResolvers,
    ) {}

    /**
     * @param iterable<RequestField> $fields
     *
     * @return array{0: list<OA\Property>, 1: list<string>} `[properties, required]`
     */
    public function propertiesAndRequired(iterable $fields): array
    {
        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($fields as $field) {
            if ($field->name === null || $field->name === '') {
                continue;
            }

            $properties[] = $this->buildProperty($field);

            if ($field->required === true) {
                $required[] = $field->name;
            }
        }

        return [$properties, $required];
    }

    private function buildProperty(RequestField $field): OA\Property
    {
        $type = $field->type;

        if ($type !== null && class_exists($type)) {
            return FieldReferenceProperty::build(
                $field->name ?? '',
                $field->descriptor()->description,
                $this->resolveClassRef($type),
            );
        }

        $property = new OA\Property(['property' => $field->name]);
        $field->descriptor()->applyTo($property);

        // Class-string `items:` resolves to `items: { $ref }`; descriptor already set type/constraints.
        if ($type === 'array' && $field->items !== null && class_exists($field->items)) {
            $ref = $this->resolveClassRef($field->items);

            $property->items = $ref !== null
                ? new OA\Items(['ref' => $ref])
                : new OA\Items(['type' => 'object']);
        }

        return $property;
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        foreach (($this->refSchemaResolvers)() as $resolver) {
            $ref = $resolver->resolveRef($class);

            if ($ref !== null) {
                return $ref;
            }
        }

        return null;
    }
}
