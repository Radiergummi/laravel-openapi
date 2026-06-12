<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Rules;

use Override;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\ResponseField;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;
use function count;
use function is_float;
use function is_int;

/**
 * Flags `FieldAttribute` subclasses ({@see PathParam}, {@see QueryParam}, {@see RequestField},
 * {@see ResponseField}) where a `min*` bound exceeds its paired `max*` bound — an always-empty
 * domain the runtime accepts silently. Only literal numeric pairs are compared; non-constant
 * expressions are skipped because static analysis can't compare them.
 *
 * Three pairs are checked: `minimum`/`maximum`, `minLength`/`maxLength`, `minItems`/`maxItems`.
 *
 * @implements Rule<Node\Attribute>
 */
final class FieldRangeOrderingRule implements Rule
{
    /**
     * Each entry is [min argument name, max argument name].
     *
     * @var list<array{string, string}>
     */
    private const array PAIRS = [
        ['minimum', 'maximum'],
        ['minLength', 'maxLength'],
        ['minItems', 'maxItems'],
    ];

    /**
     * @var array<class-string, true>
     */
    private const array FIELD_ATTRIBUTES = [
        PathParam::class => true,
        QueryParam::class => true,
        RequestField::class => true,
        ResponseField::class => true,
    ];

    #[Override]
    public function getNodeType(): string
    {
        return Node\Attribute::class;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof Node\Attribute);

        if (!isset(self::FIELD_ATTRIBUTES[$node->name->toString()])) {
            return [];
        }

        $errors = [];

        foreach (self::PAIRS as [$minName, $maxName]) {
            $min = self::scalarValue($node, $minName, $scope);
            $max = self::scalarValue($node, $maxName, $scope);

            if ($min === null || $max === null) {
                continue;
            }

            if ($min <= $max) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                "Field attribute has {$minName} ({$min}) greater than {$maxName} ({$max}) — the resulting range is empty.",
            )->identifier('openapi.field.rangeOrdering')->build();
        }

        return $errors;
    }

    /**
     * Returns the named argument's constant scalar numeric value, or `null` when the argument is
     * absent, explicitly null, or non-constant. Casts integer literals to float for uniform
     * comparison with `minimum`/`maximum` which accept `int|float`.
     */
    private static function scalarValue(Node\Attribute $attribute, string $name, Scope $scope): ?float
    {
        $argument = AttributeHelpers::getArgument($attribute, $name);

        if ($argument === null) {
            return null;
        }

        $constants = $scope->getType($argument->value)->getConstantScalarValues();

        if (count($constants) !== 1) {
            return null;
        }

        $value = $constants[0];

        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        return (float) $value;
    }
}
