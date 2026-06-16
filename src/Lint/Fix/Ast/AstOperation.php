<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Sealed base for declarative AST-mutation operations. A subclass describes *what* to change on a
 * structurally-addressed node; {@see FixOperationVisitor} knows *how*, and {@see \Radiergummi\OpenApi\Lint\Fix\FixApplicator}
 * applies it to a cloned tree and reprints with the format-preserving printer.
 *
 * @internal
 */
abstract readonly class AstOperation
{
    public function __construct(
        public TargetSelector $target,
    ) {}
}
