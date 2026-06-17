<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Sets, replaces, or removes a named argument on one attribute application, addressed by its
 * position in the target member's flat, source-order attribute list (the same enumeration the rest
 * of the backend uses).
 *
 * With `$remove === true`, deletes the named argument. Otherwise, replaces an existing argument of
 * the same name or appends a new named argument. The applicator refuses (skips) the mutation when
 * the attribute carries positional arguments that would make a named add/remove unsafe.
 *
 * @internal
 */
final readonly class SetAttributeArgument extends AstOperation
{
    public function __construct(
        TargetSelector $target,
        public int $attributeIndex,
        public string $argumentName,
        public string|int|float|bool|null $value = null,
        public bool $remove = false,
    ) {
        parent::__construct($target);
    }
}
