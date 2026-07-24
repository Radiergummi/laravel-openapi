<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

/**
 * Shared bound for the return-discovery readers that scan a method body for its single
 * unconditional return.
 *
 * @internal
 */
final class ReturnScan
{
    /**
     * Pathological-input backstop, not a semantic bound: the guard that makes a resolution sound is
     * "exactly one unconditional return", not how far the scan looked. Set far above ordinary method
     * length so an everyday run of guard clauses never hides the trailing return.
     */
    public const int STATEMENT_LIMIT = 100;
}
