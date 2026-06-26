<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;

use function array_all;
use function array_is_list;
use function is_array;
use function is_string;

/**
 * Maps a single validation field's value expression to its literal ruleset.
 *
 * Passes through literal strings and string-list constants; for array literals keeps only the
 * literal elements and drops dynamic ones (e.g. `Rule::unique()` refines validation, not the
 * schema shape). Returns null for a fully dynamic value. Shared by the readers that recover a
 * `rules` array literal from a method body, so the per-field tolerance stays in lockstep.
 *
 * @internal
 */
final readonly class RuleFieldLiteralMapper
{
    /**
     * @return null|array<int, mixed>|string
     */
    public static function map(Expr $fieldValue): string|array|null
    {
        if (!$fieldValue instanceof Array_) {
            try {
                $evaluated = AstLiteralEvaluator::evaluate($fieldValue);
            } catch (NonLiteralValueException) {
                return null;
            }

            if (is_string($evaluated)) {
                return $evaluated;
            }

            if (
                is_array($evaluated)
                && array_is_list($evaluated)
                && array_all($evaluated, static fn(mixed $element): bool => is_string($element))
            ) {
                return $evaluated;
            }

            return null;
        }

        $elements = [];

        foreach ($fieldValue->items as $item) {
            if ($item->unpack || $item->key !== null) {
                return null;
            }

            try {
                $elements[] = AstLiteralEvaluator::evaluate($item->value);
            } catch (NonLiteralValueException) {
                // Dynamic element (a Rule object, a call): keep the literal rest of the list.
                continue;
            }
        }

        return $elements;
    }
}
