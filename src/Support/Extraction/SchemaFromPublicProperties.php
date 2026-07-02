<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use ArrayAccess;
use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Attributes\Schema as SchemaAttribute;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Provenance\SchemaProvenance;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Traversable;

use function class_exists;
use function enum_exists;
use function is_a;

/**
 * Builds a reusable object schema for a plain class from its public (and constructor-promoted) typed
 * properties, and returns a pooled `$ref`. The language-level counterpart to the plugin builders
 * (Spatie Data / API Resource / FormRequest): it reads only what reflection exposes, so a typed DTO
 * gets a response schema without any convention package.
 *
 * Per-property typing is delegated to {@see PublicPropertyTypeReader} (the single authority on which
 * property types map to a concrete schema); this class adds only the recurse-into-object path, wired
 * as the reader's leaf callback so a nested DTO becomes its own pooled component. It never invents a
 * schema: a class with no usable property degrades to null, and a property the reader refuses is
 * omitted rather than stubbed. Self- and mutually-referential classes are cycle-safe.
 *
 * @internal
 */
#[Scoped]
final class SchemaFromPublicProperties
{
    /**
     * Classes whose schema is being assembled higher up the call stack, for cycle detection.
     *
     * Registration is deferred until a class is known to have a usable property, so this guard
     * cannot lean on the registry's own in-progress flag (which is set only once a build commits).
     *
     * @var array<class-string, true>
     */
    private array $inProgress = [];

    public function __construct(
        private readonly ComponentSchemaRegistry $registry,
        private readonly PublicPropertyTypeReader $propertyReader,
    ) {}

    /**
     * Registers the object schema for `$class` (idempotently) and returns the qualified `$ref`, or
     * null when the class exposes no usable public/promoted typed property (a service class, an
     * all-private class, or one whose every property refuses to type).
     *
     * Accepts any string and validates class existence itself, so callers can pass a raw type name
     * without narrowing first.
     */
    public function buildRef(string $class): ?string
    {
        // Enums are the engine's job, not the public-property walker's; a bare `name`/`value`
        // property would otherwise misrepresent them.
        if (!class_exists($class) || enum_exists($class)) {
            return null;
        }

        // A collection/container object (Collection, DataCollection, JsonResource, paginator, …) is
        // shaped by its elements, not its public properties; walking its wrapper internals would emit
        // a meaningless schema. Its element schema is a convention plugin's job, so degrade here.
        if (is_a($class, Traversable::class, allow_string: true) || is_a($class, ArrayAccess::class, allow_string: true)) {
            return null;
        }

        // An authored #[OA\Schema] is an intentional, human-supplied schema; the SwaggerPhp harvester
        // surfaces it verbatim. Inference must never pre-empt an authored schema, so degrade here.
        if (new ReflectionClass($class)->getAttributes(SchemaAttribute::class) !== []) {
            return null;
        }

        // Already built, or reserved by a cycle guard: reuse the pooled key.
        if ($this->registry->isRegisteredOrReserved($class)) {
            return $this->registry->qualifyKey($this->registry->reserveKey($class));
        }

        // Re-entrant on the same class (self/mutual reference): reserve the key now so the in-flight
        // component can be referenced, and stop recursing.
        if (isset($this->inProgress[$class])) {
            return $this->registry->qualifyKey($this->registry->reserveKey($class));
        }

        $this->inProgress[$class] = true;

        try {
            $schema = $this->assemble($class);
        } catch (ReflectionException) {
            // Guarded impossible (class_exists is checked above), but keeps the run degrading rather
            // than aborting on an exotic reflection failure.
            return null;
        } finally {
            unset($this->inProgress[$class]);
        }

        if ($schema === null) {
            return null;
        }

        $this->registry->register($class, $schema, new SchemaProvenance(self::class));

        return $this->registry->qualifyKey($this->registry->reserveKey($class));
    }

    /**
     * The object schema for a class, or null when no public/promoted typed property types.
     *
     * @param class-string $class
     *
     * @throws ReflectionException
     */
    private function assemble(string $class): ?OA\Schema
    {
        $reflection = new ReflectionClass($class);
        $leafCallback = $this->leafCallback();

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            $name = $reflectionProperty->getName();
            $property = $this->propertyReader->propertyFor($class, $name, $leafCallback);

            if ($property === null) {
                continue;
            }

            $properties[] = $property;

            $declaredType = $reflectionProperty->getType();

            // A property that cannot be absent from the serialized value is required; nullability is
            // the language-level signal, mirroring how a non-nullable @property marks a model field.
            if ($declaredType !== null && !$declaredType->allowsNull()) {
                $required[] = $name;
            }
        }

        if ($properties === []) {
            return null;
        }

        $arguments = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $arguments['required'] = $required;
        }

        return new OA\Schema($arguments);
    }

    /**
     * The reader's leaf callback: resolve a nested plain object to its pooled `$ref`, or null so the
     * reader omits the property rather than stubbing it.
     *
     * @return callable(string): ?OA\Schema
     */
    private function leafCallback(): callable
    {
        return function (string $className): ?OA\Schema {
            $reference = $this->buildRef($className);

            return $reference === null ? null : new OA\Schema(['ref' => $reference]);
        };
    }
}
