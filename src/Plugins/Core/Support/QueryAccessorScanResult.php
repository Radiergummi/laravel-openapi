<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * Result of scanning a controller method for request-accessor reads.
 *
 * `reads` lists statically-readable accessor calls in source order; `unreadableAccessors`
 * names methods whose key argument was not a static string literal (logged at generation time).
 *
 * @internal
 */
final readonly class QueryAccessorScanResult
{
    /**
     * @param list<QueryAccessorRead> $reads
     * @param list<string>            $unreadableAccessors
     */
    public function __construct(
        public array $reads = [],
        public array $unreadableAccessors = [],
    ) {}
}
