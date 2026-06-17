<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * Why a {@see Fix} was not applied during a {@see FixApplicator::apply()} run.
 *
 * @internal
 */
enum FixSkipReason: string
{
    /** The operation's target node was not found in the parsed source (reflection/AST mismatch). */
    case NodeNotFound = 'node-not-found';

    /** The format-preserving print threw, so the whole file's batch was rejected to avoid mangling. */
    case PrintFailed = 'print-failed';

    /** Another fix on the same node was kept; this one was skipped to avoid an unsafe combined edit. */
    case Conflict = 'conflict';
}
