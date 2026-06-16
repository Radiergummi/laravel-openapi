<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Structural address of the member an {@see AstOperation} mutates: the containing class FQCN and
 * the member name. Carries no line or byte positions — the operation visitor relocates the node by
 * name in the cloned tree, so the address stays valid no matter how earlier edits shift the source.
 *
 * @internal
 */
final readonly class TargetSelector
{
    public function __construct(
        public string $className,
        public TargetKind $kind,
        public string $memberName,
    ) {}
}
