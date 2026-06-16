<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\PhpStan\Rules;

use Override;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

use function count;
use function is_string;

/**
 * Edit-time counterpart of the `query-builder.filter-duplicate` lint rule. Flags two or more
 * `#[AllowedFilter]` attributes on the same action sharing the same wire name. `OperationBuilder`
 * dedups by name and silently keeps only the last, so the earlier declarations are lost.
 *
 * Names that don't resolve to a constant string are skipped; only statically comparable pairs are
 * flagged. The name is read from either the positional first argument (`#[AllowedFilter('status')]`,
 * the documented idiom) or the named `name:` argument.
 *
 * @implements Rule<Node>
 */
final class QueryBuilderFilterDuplicateRule implements Rule
{
    /**
     * @param class-string<Node> $nodeType
     */
    public function __construct(private readonly string $nodeType) {}

    #[Override]
    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $filters = AttributeHelpers::attributesNamed(
            AttributeHelpers::getAttributeGroups($node),
            AllowedFilter::class,
        );

        if (count($filters) < 2) {
            return [];
        }

        /** @var array<string, true> $seen */
        $seen = [];
        $errors = [];

        foreach ($filters as $attribute) {
            $name = self::resolveName($attribute, $scope);

            if ($name === null) {
                continue;
            }

            if (isset($seen[$name])) {
                $errors[] = RuleErrorBuilder::message(
                    "#[AllowedFilter('{$name}')] is declared more than once on this target — only the last instance is emitted.",
                )
                    ->identifier('openapi.allowedFilter.duplicate')
                    ->line($attribute->getStartLine())
                    ->build();

                continue;
            }

            $seen[$name] = true;
        }

        return $errors;
    }

    private static function resolveName(Node\Attribute $attribute, Scope $scope): ?string
    {
        // `name` is idiomatically positional; fall back to first unnamed argument.
        $argument = AttributeHelpers::getArgument($attribute, 'name')
            ?? self::firstPositionalArgument($attribute);

        if ($argument === null) {
            return null;
        }

        $constants = $scope->getType($argument->value)->getConstantScalarValues();

        if (count($constants) !== 1 || !is_string($constants[0])) {
            return null;
        }

        return $constants[0];
    }

    private static function firstPositionalArgument(Node\Attribute $attribute): ?Node\Arg
    {
        $first = $attribute->args[0] ?? null;

        return $first !== null && $first->name === null ? $first : null;
    }
}
