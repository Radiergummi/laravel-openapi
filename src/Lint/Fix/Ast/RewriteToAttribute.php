<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * Replaces a source annotation on the target member with a new attribute application, atomically:
 * the source `#[OA\*]` attributes (by flat index) or the member's `@OA` docblock are removed and the
 * new attribute prepended in a single node visit. Doing both in one operation avoids the index-shift
 * and same-node conflict an {@see AddAttribute} + {@see RemoveAttribute} pair would hit under the
 * single-pass applicator.
 *
 * Exactly one removal mode is in effect: `$attributeIndices` (attribute shape, computed against the
 * member's source-order flat attribute list) or `$docComment` (docblock shape, the replacement text
 * with the `@OA` block stripped, or `null` to drop the whole doc comment). When both are null the op
 * only adds.
 *
 * @internal
 */
final readonly class RewriteToAttribute extends AstOperation
{
    /**
     * @param class-string                              $attributeClass   The attribute to add.
     * @param array<string, null|bool|float|int|string> $arguments        Named arguments, in order.
     * @param null|list<int>                            $attributeIndices Flat indices of the `#[OA\*]`
     *                                                                    attributes to remove.
     * @param null|false|string                         $docComment       Replacement doc text, or null
     *                                                                    to drop the doc comment; false
     *                                                                    leaves the doc comment alone.
     */
    public function __construct(
        TargetSelector $target,
        public string $attributeClass,
        public array $arguments,
        public ?array $attributeIndices = null,
        public string|false|null $docComment = false,
    ) {
        parent::__construct($target);
    }
}
