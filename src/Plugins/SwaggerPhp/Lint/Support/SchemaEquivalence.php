<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

use function array_is_list;
use function array_key_exists;
use function array_values;
use function get_object_vars;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;

/**
 * Tests whether one swagger-php annotation subtree is structurally contained in another.
 *
 * Used by migration rules to detect redundant authored annotations: `authored ⊆ inferred` means
 * removing the annotation loses nothing. A genuine restriction inference cannot derive
 * (`additionalProperties: false`, etc.) fails containment and the annotation is kept.
 *
 * Canonical form drops `UNDEFINED` sentinels and `_`-prefixed internals; collections are
 * compared order-insensitively. `$ref` targets are compared by literal string (conservative).
 *
 * @internal
 */
final readonly class SchemaEquivalence
{
    /**
     * Whether every value in `$narrower` is present and equal in `$broader` (which may carry more).
     */
    public function subsumes(?OA\AbstractAnnotation $broader, ?OA\AbstractAnnotation $narrower): bool
    {
        return $this->contains($this->normalize($broader), $this->normalize($narrower));
    }

    /** Recursive containment: maps check key-by-key, lists are order-insensitive, scalars use ==. */
    private function contains(mixed $broader, mixed $narrower): bool
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
                    if ($this->contains($broaderElement, $narrowerElement)) {
                        continue 2;
                    }
                }

                return false;
            }

            return true;
        }

        foreach ($narrower as $key => $narrowerValue) {
            if (!array_key_exists($key, $broader) || !$this->contains($broader[$key], $narrowerValue)) {
                return false;
            }
        }

        return true;
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
