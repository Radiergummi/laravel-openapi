<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Provenance;

/**
 * One derived operation field with the source and reason behind its value, emitted by
 * {@see \Radiergummi\OpenApi\Support\Generator\OperationBuilder} and rendered by
 * `openapi:why --fields`. Pure data, modelled on
 * {@see \Radiergummi\OpenApi\Support\Inclusion\TraceEntry}.
 *
 * @internal
 */
final readonly class FieldProvenance
{
    /**
     * @param string       $field        Derived field, e.g. `summary`, `status`, `tags`.
     * @param string       $value        Rendered value, e.g. `201`, `List Flights`, `Flights`.
     * @param string       $source       Attribute name + scope, resolver short-class, or `default`.
     * @param string       $reason       Short human string, e.g. `store → POST`, `author override`.
     * @param list<string> $supersededBy Candidates the winner beat, e.g. `convention 'Show Flight'`.
     */
    public function __construct(
        public string $field,
        public string $value,
        public string $source,
        public string $reason,
        public array $supersededBy = [],
    ) {}
}
