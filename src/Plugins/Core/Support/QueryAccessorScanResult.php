<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * Result of scanning a controller method for request-accessor reads.
 *
 * `reads` lists every whitelisted accessor call whose parameter name could be read statically,
 * in source order; `unreadableAccessors` names the accessor methods of calls that matched the
 * whitelist but whose key argument is not a string literal (or contains a wildcard) — the
 * found-but-unreadable case, which warrants a generation-log note.
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
