<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Support;

/**
 * Result of one bounded scan for a `QueryBuilder::for(...)` fluent chain — the wire names
 * collected per allow-list kind, plus the evidence the resolver needs to phrase its
 * generation-log notes: whether a Spatie builder root and any `allowed*` calls were seen at
 * all (matched or not), and which matched calls had to drop non-literal elements.
 *
 * @internal
 */
final readonly class QueryBuilderChainScan
{
    /**
     * @param list<string> $filters             Filter names from matched `allowedFilters` calls.
     * @param list<string> $sorts               Sort field names from matched `allowedSorts` calls.
     * @param list<string> $includes            Include names from matched `allowedIncludes` calls.
     * @param bool         $builderDetected     Whether a `Spatie\QueryBuilder\QueryBuilder::for()`
     *                                          call was seen anywhere in the scanned statements.
     * @param bool         $allowedCallDetected Whether any `allowed*`-named call was seen anywhere
     *                                          in the scanned statements, rooted or not.
     * @param list<string> $unreadableCalls     Canonical names of matched `allowed*` calls that
     *                                          dropped one or more non-literal elements.
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public array $includes = [],
        public bool $builderDetected = false,
        public bool $allowedCallDetected = false,
        public array $unreadableCalls = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->filters === [] && $this->sorts === [] && $this->includes === [];
    }
}
