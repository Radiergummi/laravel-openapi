<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Structural address of the node an {@see AstOperation} mutates: the containing class FQCN, the kind
 * of node, and (for member targets) the member name. Carries no line or byte positions — the
 * operation visitor relocates the node by name in the cloned tree, so the address stays valid no
 * matter how earlier edits shift the source. `$memberName` is null only for a `ClassNode` target.
 *
 * @internal
 */
final readonly class TargetSelector
{
    public function __construct(
        public string $className,
        public TargetKind $kind,
        public ?string $memberName = null,
    ) {}
}
