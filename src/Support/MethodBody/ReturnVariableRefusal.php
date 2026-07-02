<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

/**
 * Why {@see ReturnExpressionResolver} could not reduce a returned variable to a single, stable
 * assignment expression. Callers that log map these onto their own wording; the resolver itself
 * stays log-free.
 *
 * @internal
 */
enum ReturnVariableRefusal
{
    /** The variable name is an expression (`$$name`, `${…}`), not statically knowable. */
    case DynamicallyNamedVariable;

    /** Not assigned exactly once on the unconditional path (zero, multiple, or conditional). */
    case NotAssignedOnce;

    /** Assigned once, but mutated afterwards (`$v['k'] = …`, `$v += …`), so the value is stale. */
    case MutatedAfterAssignment;
}
