<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Provenance;

/**
 * Records which builder produced the winning schema for a component, and whether that build
 * was degraded. Sits beside the stored schema in {@see
 * \Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry} as side metadata (never
 * serialized into the OpenAPI document) and is surfaced by `openapi:why`. Modelled on
 * {@see FieldProvenance}: `supersededBy` lists the losers that contested the same class.
 *
 * @internal
 */
final readonly class SchemaProvenance
{
    /**
     * @param class-string       $producer     Builder that registered the winning schema.
     * @param bool               $degraded     The producer flagged a degraded/partial result.
     * @param ?string            $reason       Short human string (why degraded / how derived).
     * @param list<class-string> $supersededBy Producers that raced this class and lost (contested).
     */
    public function __construct(
        public string $producer,
        public bool $degraded = false,
        public ?string $reason = null,
        public array $supersededBy = [],
    ) {}
}
