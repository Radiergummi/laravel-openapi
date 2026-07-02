<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Provenance;

use function is_bool;
use function is_scalar;
use function is_string;

/**
 * The winner of a precedence-ordered merge over a list of {@see FieldCandidate}s, plus the lower
 * candidates it beat. A single, standalone merge mechanic: candidate list in, winner + provenance
 * out. Deliberately not tied to {@see \Radiergummi\OpenApi\Support\Generator\OperationBuilder} so it
 * can drive field-name-keyed overlays elsewhere (e.g. field-attribute overlays applied on top of an
 * already-resolved field).
 *
 * The winning {@see self::$value} keeps its native type (an `int` status, a `list<string>` of tags);
 * projection to the render-facing {@see FieldProvenance} happens via {@see self::toProvenance()},
 * where the caller supplies the display string.
 *
 * @internal
 */
final readonly class ResolvedField
{
    /**
     * @param string       $field        Field name, e.g. `summary`, `status`, `tags`.
     * @param mixed        $value        The winning candidate's native value.
     * @param string       $source       The winning candidate's source.
     * @param string       $reason       The winning candidate's reason.
     * @param list<string> $supersededBy Superseded-labels of the present candidates the winner beat.
     */
    private function __construct(
        public string $field,
        public mixed $value,
        public string $source,
        public string $reason,
        public array $supersededBy,
    ) {}

    /**
     * Resolves a precedence-ordered candidate list to its winner: the first present candidate, with
     * every lower-precedence present candidate recorded as superseded. Returns null when no
     * candidate is present (the field has no value and contributes no provenance).
     *
     * @param list<FieldCandidate> $candidates highest precedence first
     */
    public static function merge(string $field, array $candidates): ?self
    {
        $winner = null;
        $supersededBy = [];

        foreach ($candidates as $candidate) {
            if (!$candidate->isPresent()) {
                continue;
            }

            if ($winner === null) {
                $winner = $candidate;

                continue;
            }

            $supersededBy[] = $candidate->supersededLabel ?? $candidate->source;
        }

        if ($winner === null) {
            return null;
        }

        return new self($field, $winner->value, $winner->source, $winner->reason, $supersededBy);
    }

    /**
     * Projects this resolution to the render-facing provenance entry. `$displayValue` is how the
     * winning value reads in `openapi:why --fields` (e.g. `201`, `List Flights`, `Flights`);
     * defaults to a string cast of the value for scalar fields.
     */
    public function toProvenance(?string $displayValue = null): FieldProvenance
    {
        return new FieldProvenance(
            $this->field,
            $displayValue ?? $this->stringifyValue(),
            $this->source,
            $this->reason,
            $this->supersededBy,
        );
    }

    private function stringifyValue(): string
    {
        return match (true) {
            is_string($this->value) => $this->value,
            is_bool($this->value) => $this->value ? 'true' : 'false',
            is_scalar($this->value) => (string) $this->value,
            default => '',
        };
    }
}
