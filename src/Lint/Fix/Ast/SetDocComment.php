<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Replaces (or removes) the doc comment on the target node. A non-null `$text` becomes the node's
 * new doc-comment text, reusing the original comment's source positions so the format-preserving
 * printer reprints only the doc block; a null `$text` removes the doc comment entirely, dropping its
 * physical lines.
 *
 * @internal
 */
final readonly class SetDocComment extends AstOperation
{
    public function __construct(
        TargetSelector $target,
        public ?string $text,
    ) {
        parent::__construct($target);
    }
}
