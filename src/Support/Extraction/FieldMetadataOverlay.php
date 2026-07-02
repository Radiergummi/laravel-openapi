<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Provenance\FieldCandidate;
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;
use Radiergummi\OpenApi\Support\Provenance\ResolvedField;

/**
 * The single mechanism for overlaying human-copy field metadata (`description`, `example`, `format`,
 * deprecation) onto an already-built schema or parameter. It is deliberately **policy-free**: the
 * caller supplies each sub-field's candidates in its own precedence order, and the overlay only runs
 * the {@see ResolvedField::merge} pick, applies the winner, and records provenance. Precedence stays
 * with the caller, so migrating a decoration site through the overlay preserves its exact output.
 *
 * The {@see FieldProvenance} records are produced but not yet surfaced; a later change owns the
 * label/aggregation convention and the `openapi:why` wiring that consumes them.
 *
 * @internal
 */
#[Scoped]
final class FieldMetadataOverlay
{
    public static function create(): self
    {
        return new self();
    }

    /**
     * Picks one field's winner from its precedence-ordered candidates, highest first. Returns null
     * when no candidate is present. Use this where the winning value needs further shaping before it
     * lands on the target (e.g. a suffix appended to a description); otherwise prefer {@see apply()}.
     *
     * @param list<FieldCandidate> $candidates
     */
    public function resolve(string $field, array $candidates): ?ResolvedField
    {
        return ResolvedField::merge($field, $candidates);
    }

    /**
     * Resolves each sub-field from its own precedence-ordered candidate list and writes the winning
     * value onto the target. A sub-field with no present candidate is left untouched, so inferred
     * values already on the target survive. Returns one provenance record per resolved sub-field.
     *
     * @param array<string, list<FieldCandidate>> $candidates sub-field name (`description`,
     *                                                        `example`, `format`, `deprecated`) =>
     *                                                        its candidates, highest precedence first
     *
     * @return list<FieldProvenance>
     */
    public function apply(OA\AbstractAnnotation $target, array $candidates): array
    {
        $provenance = [];

        foreach ($candidates as $field => $fieldCandidates) {
            $resolved = ResolvedField::merge($field, $fieldCandidates);

            if ($resolved === null) {
                continue;
            }

            $target->{$field} = $resolved->value;
            $provenance[] = $resolved->toProvenance();
        }

        return $provenance;
    }
}
