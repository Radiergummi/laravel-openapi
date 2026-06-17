<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Adds a new attribute application to the target member (a class, method, property, or promoted
 * parameter), synthesised from a fully-qualified attribute class and an ordered map of named scalar
 * arguments. The new attribute is prepended as its own {@see \PhpParser\Node\AttributeGroup} above
 * any existing groups, giving a deterministic, byte-stable position.
 *
 * If the member already carries the attribute class, the operation is a no-op (nothing is applied):
 * the *fixer* decides add-vs-modify, the op only inserts. The argument values share
 * {@see SetAttributeArgument}'s scalar domain; a structured-value variant is a separate concern.
 *
 * @internal
 */
final readonly class AddAttribute extends AstOperation
{
    /**
     * @param class-string                                  $attributeClass The attribute to add, e.g.
     *                                                                      `Operation::class`.
     * @param array<string, string|int|float|bool|null>     $arguments      Named arguments in the
     *                                                                      order they should render.
     */
    public function __construct(
        TargetSelector $target,
        public string $attributeClass,
        public array $arguments = [],
    ) {
        parent::__construct($target);
    }
}
