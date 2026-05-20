<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Closure;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Enums\ComponentType;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Extensions\SchemaContext;
use Radiergummi\OpenApi\Core\Extractors\FieldDescriptor;

use function array_key_exists;
use function array_pop;
use function array_reverse;
use function array_values;
use function class_basename;
use function explode;
use function md5;
use function substr;

/**
 * Holds all Data class schemas registered during an OpenAPI generation run.
 *
 * **Deduplication:** registering the same class twice is idempotent — the first schema wins and
 * later calls are no-ops. Iteration order is insertion order, so the generated spec is
 * deterministic across runs.
 *
 * **Key generation:** the component key is the class basename (e.g. `CreateProjectData`). If two
 * Data classes in different namespaces share a basename, the second one is disambiguated with its
 * parent directory segment (e.g. `Projects.CreateProjectData`).
 */
final class ComponentSchemaRegistry
{
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
     * Inverse index for {@see isKeyTaken()} fast-path. Populated alongside
     * {@see $classToKey} so collision probes are O(1) instead of O(N).
     *
     * @var array<string, string>
     */
    private array $keyToClass = [];

    /**
     * @var array<class-string, true>
     */
    private array $inProgress = [];

    /**
     * Empty array means "extraction was attempted and yielded nothing" — distinct from
     * `null` ("not yet attempted") as returned by {@see compiledFields()}.
     *
     * @var array<class-string, array<string, FieldDescriptor>>
     */
    private array $compiledFields = [];

    /**
     * Per-class map of field-name → items FieldDescriptor, derived from `foo.*` validation rules.
     * Null means "not yet computed"; an empty array means "computed, no wildcard rules found".
     *
     * @var array<class-string, array<string, FieldDescriptor>>
     */
    private array $compiledItemsFields = [];

    /**
     * Whether the FormRequest class has any file fields, computed once during schema building.
     * Null means "not yet computed".
     *
     * @var array<class-string, bool>
     */
    private array $hasFileFields = [];

    /**
     * Reserves the disambiguated component key for `$className` without storing a schema.
     *
     * Used internally by {@see self::buildOnce()} as part of the cycle guard: returning a
     * reserved key on recursive re-entry lets the caller emit a `$ref` pointing at the same
     * key {@see register()} will ultimately assign.
     *
     * Idempotent — a second call for the same class is a no-op.
     *
     * Accepts any string: the key is derived purely from the name, so the
     * class need not exist (used for synthetic and not-yet-loaded classes).
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
     * If the key is already taken by a different class (basename collision), the new class is
     * disambiguated with its parent namespace segment.
     *
     * @param class-string $className
     */
    public function register(string $className, OA\Schema $schema): void
    {
        // First schema wins — honour the docblock's idempotency guarantee without relying on every
        // caller to check isRegisteredOrReserved() first.
        if (array_key_exists($className, $this->classToKey) && array_key_exists($this->classToKey[$className], $this->schemas)) {
            return;
        }

        // Key may already be reserved by reserveKey() — reuse it so the $ref emitted by the cycle
        // guard and the component key assigned here remain in sync.
        $key = $this->reserveKey($className);

        // swagger-php requires the `schema` property to equal the component key so that $ref
        // resolution works during validation.
        $schema->schema = $key;

        OpenApiExtensions::applySchemaTransformers(
            $schema,
            new SchemaContext($key, $className),
        );

        $this->schemas[$key] = $schema;
    }

    /**
     * Used for shared envelopes such as the JSON:API error response schema.
     *
     * This is idempotent: Later calls with the same key are no-ops.
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
    }

    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->schemas);
    }

    /**
     * Registers a named response component under `components.responses`.
     *
     * This is idempotent: Later calls with the same key are no-ops.
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
     * Cycle-guarded build-and-register: invokes `$factory` exactly once to produce the schema, then
     * registers it under the disambiguated component key and returns the key.
     *
     * If `$className` is being built higher up the call stack (recursive `$ref`), the reserved key
     * is returned without invoking the factory — the caller can emit a `$ref` pointing at the same
     * key {@see register()} will ultimately assign. Already-registered classes also short-circuit.
     *
     * Exceptions from `$factory` propagate, but the in-progress flag is always cleared so a
     * later retry for the same class is not stuck.
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
     * Returns true when the class has had its component key reserved OR its schema fully registered
     *
     * This covers both the "cycle guard" state (key reserved, schema still building) and the
     * "fully done" state — i.e., it answers "have we started processing this class?" rather than
     * "is the schema stored?"
     *
     * @param class-string $className
     */
    public function isRegisteredOrReserved(string $className): bool
    {
        return array_key_exists($className, $this->classToKey);
    }

    /**
     * @param class-string $className
     */
    private function markInProgress(string $className): void
    {
        $this->inProgress[$className] = true;
    }

    /**
     * @param class-string $className
     */
    private function isInProgress(string $className): bool
    {
        return array_key_exists($className, $this->inProgress);
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
     * Returns the items-field map derived from `foo.*` wildcard validation rules.
     *
     * Null means not yet computed; an empty array means computed, but no wildcard rules are found.
     *
     * @param class-string $class
     *
     * @return null|array<string, FieldDescriptor>
     */
    public function compiledItemsFields(string $class): ?array
    {
        return $this->compiledItemsFields[$class] ?? null;
    }

    /**
     * @param class-string                   $class
     * @param array<string, FieldDescriptor> $fields
     */
    public function setCompiledItemsFields(string $class, array $fields): void
    {
        $this->compiledItemsFields[$class] = $fields;
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
        return "#/components/{$type->value}/{$key}";
    }

    /**
     * Resets all accumulated state.
     *
     * Under the current `scoped` container binding each generation run receives a fresh instance,
     * so this method is a no-op in normal operation. It is retained for call sites in
     * {@see OpenApiGenerator::generate()} (belt-and-suspenders) and for test isolation.
     */
    public function reset(): void
    {
        $this->schemas = [];
        $this->responses = [];
        $this->classToKey = [];
        $this->keyToClass = [];
        $this->inProgress = [];
        $this->compiledFields = [];
        $this->compiledItemsFields = [];
        $this->hasFileFields = [];
    }

    /**
     * Derives a unique, human-readable component key for `$className`.
     *
     * 1. Try the bare basename (e.g. `CreateData`).
     * 2. On collision, prepend successive ancestor namespace segments, skipping generic container
     *    segments (`Data`, `Domain`), until the key is unique. E.g. `App\Domain\Foo\Bar\CreateData`
     *    → `Bar.CreateData`, then if still taken → `Foo.Bar.CreateData`, and so on.
     * 3. If the full namespace is exhausted and the key is still taken (extremely unlikely — would
     *    require two classes with identical FQCNs), append a short hash suffix as a last resort.
     *
     * OpenAPI component keys are restricted to `[A-Za-z0-9._-]`, so backslashes are replaced
     * with dots.
     */
    private function deriveKey(string $className): string
    {
        $basename = class_basename($className);

        if (!$this->isKeyTaken($basename, $className)) {
            return $basename;
        }

        // Collect all namespace segments except the class name itself, in nearest-first
        // order (innermost → outermost), filtering generic segments.
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

        // Walk from innermost ancestor outward, accumulating prefix segments.
        $prefix = '';

        foreach ($ancestors as $segment) {
            $prefix = $prefix === '' ? $segment : "{$segment}.{$prefix}";
            $qualified = "{$prefix}.{$basename}";

            if (!$this->isKeyTaken($qualified, $className)) {
                return $qualified;
            }
        }

        // Last resort: the full namespace prefix is still ambiguous — append a short hash to
        // guarantee uniqueness.
        $hash = substr(md5($className), 0, 6);

        return $prefix === ''
            ? "{$basename}.{$hash}"
            : "{$prefix}.{$basename}.{$hash}";
    }

    private function isKeyTaken(string $key, string $forClass): bool
    {
        $owner = $this->keyToClass[$key] ?? null;

        return $owner !== null && $owner !== $forClass;
    }
}
