<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Support;

/**
 * Result of one bounded scan for a `QueryBuilder::for(...)` fluent chain.
 *
 * @internal
 */
final readonly class QueryBuilderChainScan
{
    /**
     * @param list<string> $filters             Filter names from matched `allowedFilters` calls.
     * @param list<string> $sorts               Sort field names from matched `allowedSorts` calls.
     * @param list<string> $includes            Include names from matched `allowedIncludes` calls.
     * @param bool         $builderDetected     Whether a `QueryBuilder::for()` call was seen.
     * @param bool         $allowedCallDetected Whether any `allowed*` call was seen, rooted or not.
     * @param list<string> $unreadableCalls     `allowed*` calls that dropped non-literal elements.
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
