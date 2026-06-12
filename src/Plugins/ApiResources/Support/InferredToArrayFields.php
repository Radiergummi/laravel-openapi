<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

/**
 * The outcome of reading a Resource's `toArray()` literal: the inferred fields in literal
 * order, plus whether a `merge()` / `mergeWhen()` payload had to be skipped because it was
 * not a literal array (its keys exist at runtime but cannot be documented).
 *
 * @internal
 */
final readonly class InferredToArrayFields
{
    /**
     * @param list<InferredResourceField> $fields
     */
    public function __construct(
        public array $fields,
        public bool $hasUnreadableMergePayload,
    ) {}
}
