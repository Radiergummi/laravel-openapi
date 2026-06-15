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
 * Turns a list of `#[RequestField]`s into the `properties` + `required` parts of an object schema.
 * Shared by {@see \Radiergummi\OpenApi\Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver}
 * and {@see \Radiergummi\OpenApi\Plugins\Core\Resolvers\DiscriminatedRequestSchemaResolver}.
 *
 * A class-string `type:` resolves to a `$ref` through the ref-resolver chain (mirroring the
 * response-side `#[ResourceField]`); a class-string `items:` on a `type: 'array'` field resolves
 * to `items: { $ref }`. Both degrade to a permissive object on a chain miss.
 *
 * @internal
 */
#[Scoped]
final readonly class RequestFieldObjectBuilder
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy chain consulted for class-string fields.
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

        // A class-string `type:` resolves to a `$ref` (or a permissive object on a chain miss).
        if ($type !== null && class_exists($type)) {
            return FieldReferenceProperty::build(
                $field->name ?? '',
                $field->descriptor()->description,
                $this->resolveClassRef($type),
            );
        }

        $property = new OA\Property(['property' => $field->name]);
        $field->descriptor()->applyTo($property);

        // array-of-$ref: a class-string `items:` on an array field resolves to `items: { $ref }`
        // via the same resolver chain as `type:`. The descriptor above already set `type: array`
        // plus any min/max/unique constraints and a scalar `items` placeholder — only the items
        // schema is overridden here. An unresolvable class degrades to a permissive object item,
        // symmetric with the single-ref `type: object` fallback above.
        if ($type === 'array' && $field->items !== null && class_exists($field->items)) {
            $ref = $this->resolveClassRef($field->items);

            $property->items = $ref !== null
                ? new OA\Items(['ref' => $ref])
                : new OA\Items(['type' => 'object']);
        }

        return $property;
    }

    /**
     * Walks the ref-resolver chain and returns the first matching `$ref` string, or null.
     *
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
