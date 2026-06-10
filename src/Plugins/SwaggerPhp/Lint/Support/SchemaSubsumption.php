<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

use function array_values;
use function get_object_vars;
use function is_array;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;

/**
 * Schema-level redundancy comparison: a single {@see SchemaEquivalence::subsumes()} of the authored
 * component schema against inference's schema for the same class. The comparator behind
 * {@see \Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference}.
 *
 * With an empty candidate this is exactly plain subsumption — the redundant case. With a non-empty
 * candidate the contributed `OA\Property` annotations are folded onto the inferred schema by property
 * name before comparing (`inference ⊕ candidate`), so an authored `#[OA\Property(description:)]` a
 * `#[ResponseField]` would restate counts as reproduced — the replaceable case #122 part 2 builds on.
 *
 * @internal
 */
final readonly class SchemaSubsumption implements OaRedundancyComparator
{
    public function __construct(private SchemaEquivalence $equivalence) {}

    /**
     * @param list<OA\AbstractAnnotation> $candidate
     */
    public function subsumes(OA\AbstractAnnotation $inferred, OA\AbstractAnnotation $authored, array $candidate = []): bool
    {
        if ($candidate !== [] && $inferred instanceof OA\Schema) {
            $inferred = $this->augment($inferred, $candidate);
        }

        return $this->equivalence->subsumes($inferred, $authored);
    }

    /**
     * Fold the candidate replacement annotations onto a copy of the inferred schema: each candidate
     * `OA\Property` merges its set fields into the matching inferred property (by name), or adds a new
     * one. Copies are made so the shared inference-only view is never mutated.
     *
     * @param list<OA\AbstractAnnotation> $candidate
     */
    private function augment(OA\Schema $inferred, array $candidate): OA\Schema
    {
        $properties = [];

        foreach (is_array($inferred->properties) ? $inferred->properties : [] as $property) {
            $properties[(string) $property->property] = clone $property;
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
