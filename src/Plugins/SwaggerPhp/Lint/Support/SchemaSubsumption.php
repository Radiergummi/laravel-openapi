<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;
use Override;

use function array_values;
use function get_object_vars;
use function is_array;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;

/**
 * Compares an authored component schema against the inferred schema for redundancy.
 *
 * When `$candidate` is non-empty, its `OA\Property` annotations are merged onto the inferred
 * schema before comparing, so a description-only authored property does not count as redundant.
 *
 * @internal
 */
final readonly class SchemaSubsumption implements OaRedundancyComparator
{
    public function __construct(private SchemaEquivalence $equivalence) {}

    /**
     * @param list<OA\AbstractAnnotation> $candidate
     */
    #[Override]
    public function subsumes(
        OA\AbstractAnnotation $inferred,
        OA\AbstractAnnotation $authored,
        array $candidate = [],
    ): bool {
        if ($candidate !== [] && $inferred instanceof OA\Schema) {
            $inferred = $this->augment($inferred, $candidate);
        }

        return $this->equivalence->subsumes($inferred, $authored);
    }

    /**
     * Merges candidate `OA\Property` annotations onto a clone of the inferred schema by property name.
     *
     * @param list<OA\AbstractAnnotation> $candidate
     */
    private function augment(OA\Schema $inferred, array $candidate): OA\Schema
    {
        $properties = [];

        foreach (is_array($inferred->properties) ? $inferred->properties : [] as $property) {
            $properties[$property->property] = clone $property;
        }

        foreach ($candidate as $replacement) {
            if (!$replacement instanceof OA\Property) {
                continue;
            }

            $name = (string) $replacement->property;
            $target = $properties[$name] ?? new OA\Property(['property' => $name]);

            foreach (get_object_vars($replacement) as $key => $value) {
                if ($key === 'property' || str_starts_with($key, '_') || is_undefined($value)) {
                    continue;
                }

                $target->{$key} = $value;
            }

            $properties[$name] = $target;
        }

        $augmented = clone $inferred;
        $augmented->properties = array_values($properties);

        return $augmented;
    }
}
