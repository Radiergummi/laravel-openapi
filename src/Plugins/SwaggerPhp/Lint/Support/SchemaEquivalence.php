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
 * Decides whether one swagger-php annotation subtree is structurally **contained in** another, by
 * reducing both to a canonical form and testing recursive containment.
 *
 * This is the redundancy test for the migration rules: an authored annotation is redundant when
 * inference reproduces *everything it says* — and inference may say *more* (a synthesised `example`,
 * a discovered property), so strict equality would almost never hold. Subsumption
 * (`authored ⊆ inferred`) is the sound test: removing an annotation fully contained in inference's
 * output loses nothing, while a genuine restriction the author added (`additionalProperties: false`,
 * a `description` inference cannot derive) is a key inference never emits — containment fails and the
 * annotation is correctly kept.
 *
 * The canonical form drops swagger-php's `UNDEFINED` sentinels and `_`-prefixed bookkeeping, and is
 * order-insensitive on collections so declaration order never affects the verdict. `$ref`s are
 * compared by literal string; a differing target fails containment (the conservative direction).
 *
 * @internal
 */
final readonly class SchemaEquivalence
{
    /**
     * Whether `$narrower` is structurally contained in `$broader` — every value the narrower side
     * specifies is present and equal in the broader side, which may carry more.
     */
    public function subsumes(?OA\AbstractAnnotation $broader, ?OA\AbstractAnnotation $narrower): bool
    {
        return $this->contains($this->normalize($broader), $this->normalize($narrower));
    }

    /**
     * Recursive containment over canonical values. Maps: every narrower key present and contained
     * in the broader value. Lists: every narrower element contained in *some* broader element
     * (order-insensitive). Scalars: equal.
     */
    private function contains(mixed $broader, mixed $narrower): bool
    {
        if (!is_array($narrower)) {
            return $broader == $narrower;
        }

        if (!is_array($broader)) {
            return false;
        }

        // Compare as an unordered value-collection when either side is a list. swagger-php
        // serialises keyed collections like `content` (by media type) and `responses` (by status)
        // as a list on one side and a keyed map on the other; the key is redundant with a property
        // on each element, so matching by value is correct. Only when *both* sides are keyed maps do
        // keys carry meaning (a schema's `properties`, keyed by property name).
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
     * Reduce an annotation, array, or scalar to a canonical, comparable value: annotations become
     * key-sorted maps of their set properties; lists are normalized element-wise and sorted; the
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
            // Skip swagger-php's internal bookkeeping (_context, _unmerged, _analysis, …) and any
            // property the author never set.
            if (str_starts_with($key, '_') || is_undefined($value)) {
                continue;
            }

            // On an `OA\Schema`, the `schema` property is the component *name* — an implementation
            // detail of the serialized document, not part of what the schema describes; comparison
            // is provenance-based (by source class), so a differing authored vs inferred name must
            // not affect the verdict. On other annotations (`OA\MediaType`, `OA\Parameter`) the
            // `schema` property is the nested schema itself, which must be compared.
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

        // Re-key a list so dropping an UNDEFINED element keeps it a list; `contains()` matches both
        // lists and maps order-insensitively, so no canonical ordering is needed here.
        return $wasList ? array_values($normalized) : $normalized;
    }
}
