<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Closure;
use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\SchemaName;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\SchemaContext;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;
use ReflectionClass;

use function array_key_exists;
use function array_pop;
use function array_reverse;
use function array_values;
use function class_basename;
use function class_exists;
use function explode;
use function md5;
use function substr;

/**
 * Registration is idempotent (first schema wins). Keys are derived from the class basename and
 * disambiguated with ancestor namespace segments when two classes share a basename.
 *
 * @internal
 */
#[Scoped]
final class ComponentSchemaRegistry
{
    /**
     * Sentinel owner for keys claimed by {@see registerNamed()}.
     *
     * NUL prefix guarantees it never collides with a real class-string.
     */
    private const string NAMED_KEY_OWNER = "\0named";
    /**
     * @var array<string, OA\Schema>
     */
    private array $schemas = [];
    /**
     * @var array<string, OA\Response>
     */
    private array $responses = [];
    /**
     * @var array<string, string>
     */
    private array $classToKey = [];
    /**
     * Inverse of {@see $classToKey} for O(1) collision probes.
     *
     * @var array<string, string>
     */
    private array $keyToClass = [];
    /**
     * @var array<class-string, true>
     */
    private array $inProgress = [];
    /**
     * Empty array = extraction attempted but yielded nothing; null = not yet attempted.
     *
     * @var array<class-string, array<string, FieldDescriptor>>
     */
    private array $compiledFields = [];
    /**
     * Null = not yet computed.
     *
     * @var array<class-string, bool>
     */
    private array $hasFileFields = [];

    /**
     * Registers a schema under an explicit key (e.g. shared error envelopes).
     *
     * Reserves the key so a later class registration with the same basename is disambiguated
     * rather than silently overwriting it. Idempotent.
     */
    public function registerNamed(string $key, OA\Schema $schema): void
    {
        if (array_key_exists($key, $this->schemas)) {
            return;
        }

        $schema->schema = $key;

        OpenApiExtensions::applySchemaTransformers(
            $schema,
            new SchemaContext($key, null),
        );

        $this->schemas[$key] = $schema;
        $this->keyToClass[$key] = self::NAMED_KEY_OWNER;
    }

    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->schemas);
    }

    /**
     * Returns the schema for `$key`, or null. Lets a contributor distinguish its own idempotent
     * re-registration from a genuine collision with a different schema.
     */
    public function schemaForKey(string $key): ?OA\Schema
    {
        return $this->schemas[$key] ?? null;
    }

    /**
     * Registers a named response under `components.responses`. Idempotent.
     */
    public function registerNamedResponse(string $key, OA\Response $response): void
    {
        if (array_key_exists($key, $this->responses)) {
            return;
        }

        $this->responses[$key] = $response;
    }

    public function hasResponseKey(string $key): bool
    {
        return array_key_exists($key, $this->responses);
    }

    /**
     * @return list<OA\Response>
     */
    public function allResponses(): array
    {
        return array_values($this->responses);
    }

    /**
     * Invokes `$factory` exactly once, registers the schema, and returns the component key.
     *
     * If `$className` is already in progress (recursive `$ref`) or registered, returns the
     * reserved key without calling the factory. Exceptions propagate; the in-progress flag is
     * always cleared.
     *
     * @param class-string         $className
     * @param Closure(): OA\Schema $factory
     */
    public function buildOnce(string $className, Closure $factory): string
    {
        if ($this->isInProgress($className) || $this->isRegisteredOrReserved($className)) {
            return $this->reserveKey($className);
        }

        $this->markInProgress($className);

        try {
            $this->register($className, $factory());
        } finally {
            $this->markComplete($className);
        }

        return $this->reserveKey($className);
    }

    /**
     * Whether `$className` is currently being built by {@see buildOnce()} higher up the call stack.
     *
     * Exposed so plugin factories can detect re-entrance and emit a `$ref` placeholder instead of
     * triggering a nested rebuild.
     *
     * @param class-string $className
     */
    public function isInProgress(string $className): bool
    {
        return array_key_exists($className, $this->inProgress);
    }

    /**
     * Returns true once a key has been reserved for `$className`, whether or not its schema is
     * fully stored yet ("have we started?" not "are we done?").
     *
     * @param class-string $className
     */
    public function isRegisteredOrReserved(string $className): bool
    {
        return array_key_exists($className, $this->classToKey);
    }

    /**
     * Reserves the component key for `$className` without storing a schema. Idempotent.
     *
     * The class need not exist; used by the cycle guard so recursive re-entry can emit a `$ref`
     * to the key that {@see register()} will ultimately assign.
     */
    public function reserveKey(string $className): string
    {
        if (!array_key_exists($className, $this->classToKey)) {
            $key = $this->deriveKey($className);
            $this->classToKey[$className] = $key;
            $this->keyToClass[$key] = $className;
        }

        return $this->classToKey[$className];
    }

    /**
     * Derives a unique component key for `$className`.
     *
     * Tries the bare basename first, then prepends ancestor namespace segments (skipping generic
     * ones like `Data`, `Domain`) until unique. Falls back to a short hash suffix if the full
     * namespace is exhausted. {@see SchemaName} overrides derivation entirely; two classes with
     * the same explicit name throw {@see DuplicateSchemaNameException}.
     */
    private function deriveKey(string $className): string
    {
        $explicit = $this->explicitSchemaName($className);

        if ($explicit !== null) {
            if ($this->isKeyTaken($explicit, $className)) {
                throw DuplicateSchemaNameException::between(
                    $explicit,
                    $this->ownerLabel($explicit),
                    $className,
                );
            }

            return $explicit;
        }

        $basename = class_basename($className);

        if (!$this->isKeyTaken($basename, $className)) {
            return $basename;
        }

        $parts = explode('\\', $className);
        array_pop($parts); // remove the class name

        $ancestors = [];

        $skipParts = ['App', 'Controllers', 'Data', 'Domain', 'External', 'Global', 'Http', 'Internal'];

        foreach (array_reverse($parts) as $part) {
            if (in_array($part, $skipParts, strict: true)) {
                continue;
            }

            $ancestors[] = $part;
        }

        $prefix = '';

        foreach ($ancestors as $segment) {
            $prefix = "{$segment}{$prefix}";
            $qualified = "{$prefix}{$basename}";

            if (!$this->isKeyTaken($qualified, $className)) {
                return $qualified;
            }
        }

        // Full namespace prefix exhausted; append a short hash to guarantee uniqueness.
        $hash = substr(md5($className), 0, 6);

        return "{$prefix}{$basename}{$hash}";
    }

    /**
     * Returns the name from a {@see SchemaName} attribute on `$className`, or null.
     * Non-loadable class names simply return null and fall through to derivation.
     */
    private function explicitSchemaName(string $className): ?string
    {
        if (!class_exists($className)) {
            return null;
        }

        $attributes = new ReflectionClass($className)->getAttributes(SchemaName::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->name;
    }

    private function isKeyTaken(string $key, string $forClass): bool
    {
        $owner = $this->keyToClass[$key] ?? null;

        return $owner !== null && $owner !== $forClass;
    }

    /**
     * Human-readable owner of a taken key for error messages.
     */
    private function ownerLabel(string $key): string
    {
        // Only called after isKeyTaken() returned true, so the key is always present.
        $owner = $this->keyToClass[$key];

        return $owner === self::NAMED_KEY_OWNER ? 'a reserved schema' : $owner;
    }

    /**
     * @param class-string $className
     */
    private function markInProgress(string $className): void
    {
        $this->inProgress[$className] = true;
    }

    /**
     * Stores a schema for `$className`. First schema wins; idempotent.
     *
     * @param class-string $className
     */
    public function register(string $className, OA\Schema $schema): void
    {
        if (array_key_exists($className, $this->classToKey) && array_key_exists(
            $this->classToKey[$className],
            $this->schemas,
        )) {
            return;
        }

        // Reuse a key already reserved by the cycle guard so $ref and component key stay in sync.
        $key = $this->reserveKey($className);

        // swagger-php requires `schema` to equal the component key for $ref resolution.
        $schema->schema = $key;

        OpenApiExtensions::applySchemaTransformers(
            $schema,
            new SchemaContext($key, $className),
        );

        $this->schemas[$key] = $schema;
    }

    /**
     * @param class-string $className
     */
    private function markComplete(string $className): void
    {
        unset($this->inProgress[$className]);
    }

    /**
     * @param class-string $className
     */
    public function keyFor(string $className): ?string
    {
        return $this->classToKey[$className] ?? null;
    }

    /**
     * Returns the `componentKey => class-string` map, excluding keys registered via
     * {@see registerNamed()} (which carry the sentinel, not a real class).
     *
     * Used by the lint suppression collector to walk `#[IgnoreLint]` attributes on payload classes
     * that may never appear as method parameters.
     *
     * @return array<string, class-string>
     */
    public function componentClassMap(): array
    {
        $map = [];

        foreach ($this->keyToClass as $key => $owner) {
            if ($owner === self::NAMED_KEY_OWNER) {
                continue;
            }

            // Pint's phpdoc_to_comment fixer would demote a /** @var */ on foreach variables;
            // using a fresh local keeps the annotation intact.
            /** @var class-string $classString */
            $classString = $owner;
            $map[$key] = $classString;
        }

        return $map;
    }

    /**
     * @return list<OA\Schema>
     */
    public function all(): array
    {
        return array_values($this->schemas);
    }

    /**
     * @param class-string $class
     *
     * @return null|array<string, FieldDescriptor>
     */
    public function compiledFields(string $class): ?array
    {
        return $this->compiledFields[$class] ?? null;
    }

    /**
     * @param class-string                   $class
     * @param array<string, FieldDescriptor> $fields
     */
    public function setCompiledFields(string $class, array $fields): void
    {
        $this->compiledFields[$class] = $fields;
    }

    /**
     * @param class-string $class
     */
    public function getHasFileFields(string $class): ?bool
    {
        return $this->hasFileFields[$class] ?? null;
    }

    /**
     * @param class-string $class
     */
    public function setHasFileFields(string $class, bool $value): void
    {
        $this->hasFileFields[$class] = $value;
    }

    public function qualifyKey(string $key, ComponentType $type = ComponentType::Schemas): string
    {
        return ComponentReference::pointer($key, $type);
    }
}
