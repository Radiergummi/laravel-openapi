<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

use function array_is_list;
use function array_key_exists;
use function array_values;
use function get_object_vars;
use function is_string;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * Tests whether one swagger-php annotation subtree is structurally contained in another.
 *
 * Used by migration rules to detect redundant authored annotations: `authored ⊆ inferred` means
 * removing the annotation loses nothing. A genuine restriction inference cannot derive
 * (`additionalProperties: false`, etc.) fails containment and the annotation is kept.
 *
 * Canonical form drops `UNDEFINED` sentinels and `_`-prefixed internals; collections are
 * compared order-insensitively. Equal `$ref` strings match literally. When a {@see SchemaRefResolver}
 * is injected, two *differing* `$ref` names are followed to their target schemas and those compared,
 * so a component the author and convention named differently does not defeat the verdict; an
 * unresolvable ref is conservatively treated as not contained (the annotation is kept).
 *
 * @internal
 */
final readonly class SchemaEquivalence
{
    public function __construct(private ?SchemaRefResolver $refs = null) {}

    /**
     * Whether every value in `$narrower` is present and equal in `$broader` (which may carry more).
     */
    public function subsumes(?OA\AbstractAnnotation $broader, ?OA\AbstractAnnotation $narrower): bool
    {
        return $this->contains($this->normalize($broader), $this->normalize($narrower), []);
    }

    /**
     * Recursive containment: maps check key-by-key, lists are order-insensitive, scalars use ==.
     *
     * @param array<string, string> $visited Ref-name pairs (`inferred => authored`) already being
     *                                       compared up the stack, so a recursive graph terminates.
     */
    private function contains(mixed $broader, mixed $narrower, array $visited): bool
    {
        if (!is_array($narrower)) {
            return $broader == $narrower;
        }

        if (!is_array($broader)) {
            return false;
        }

        // swagger-php sometimes serialises keyed collections as lists; treat either-list as
        // an unordered collection. Keys only matter when both sides are keyed maps.
        if (array_is_list($narrower) || array_is_list($broader)) {
            foreach ($narrower as $narrowerElement) {
                foreach ($broader as $broaderElement) {
                    if ($this->contains($broaderElement, $narrowerElement, $visited)) {
                        continue 2;
                    }
                }

                return false;
            }

            return true;
        }

        if (($followed = $this->followDifferingRefs($broader, $narrower, $visited)) !== null) {
            return $followed;
        }

        foreach ($narrower as $key => $narrowerValue) {
            if (!array_key_exists($key, $broader) || !$this->contains($broader[$key], $narrowerValue, $visited)) {
                return false;
            }
        }

        return true;
    }

    /**
     * When both maps carry a *differing* `ref` name, resolves each side to its target schema and
     * compares those instead. Equal refs already pass via `==` and never reach here. Returns null
     * when the branch does not apply (no resolver, no/equal refs), so the caller falls back to the
     * literal map comparison; a true/false result short-circuits it.
     *
     * @param array<array-key, mixed> $broader
     * @param array<array-key, mixed> $narrower
     * @param array<string, string>   $visited
     */
    private function followDifferingRefs(array $broader, array $narrower, array $visited): ?bool
    {
        $broaderRef = $broader['ref'] ?? null;
        $narrowerRef = $narrower['ref'] ?? null;

        if ($this->refs === null || !is_string($broaderRef) || !is_string($narrowerRef) || $broaderRef === $narrowerRef) {
            return null;
        }

        $broaderName = self::refName($broaderRef);
        $narrowerName = self::refName($narrowerRef);

        // A cycle re-entering the same pair is established by the outer frame (coinductive).
        if (($visited[$broaderName] ?? null) === $narrowerName) {
            return true;
        }

        $broaderTarget = $this->refs->resolveInferred($broaderName);
        $narrowerTarget = $this->refs->resolveAuthored($narrowerName);

        // Conservative: an unresolvable target is never treated as contained.
        if ($broaderTarget === null || $narrowerTarget === null) {
            return false;
        }

        return $this->contains(
            $this->normalize($broaderTarget),
            $this->normalize($narrowerTarget),
            [...$visited, $broaderName => $narrowerName],
        );
    }

    /** The component name from a `#/components/schemas/Name` pointer (or the string as-is). */
    private static function refName(string $ref): string
    {
        $position = strrpos($ref, '/');

        return $position === false ? $ref : substr($ref, $position + 1);
    }

    /**
     * Reduce to a canonical comparable value: annotations become key-sorted property maps; the
     * `UNDEFINED` sentinel and `_`-prefixed internals are dropped.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_undefined($value) || $value === null) {
            return null;
        }

        if ($value instanceof OA\AbstractAnnotation) {
            return $this->normalizeAnnotation($value);
        }

        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAnnotation(OA\AbstractAnnotation $annotation): array
    {
        $normalized = [];

        foreach (get_object_vars($annotation) as $key => $value) {
            // Skip swagger-php internals (_context, _unmerged, …) and unset properties.
            if (str_starts_with($key, '_') || is_undefined($value)) {
                continue;
            }

            // On OA\Schema, `schema` is the component name (not a descriptor); skip it so
            // differing authored vs inferred names don't affect the verdict.
            if ($key === 'schema' && $annotation instanceof OA\Schema) {
                continue;
            }

            $normalized[$key] = $this->normalize($value);
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $value): array
    {
        $wasList = array_is_list($value);
        $normalized = [];

        foreach ($value as $key => $element) {
            if (is_undefined($element)) {
                continue;
            }

            $normalized[$key] = $this->normalize($element);
        }

        return $wasList ? array_values($normalized) : $normalized;
    }
}
