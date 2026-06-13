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
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function count;

/**
 * Flags `#[RequestField]` attributes on methods or functions that omit `name:`. When stacked on a
 * method, the field name cannot be derived from the target (unlike properties and promoted
 * constructor parameters, where the property/parameter name is used). Without `name:`, the runtime
 * silently drops the field — this rule surfaces the mistake at edit time.
 *
 * Property and promoted-parameter placements are not visited: there the name is legitimately
 * derived from the target. One service is registered per declaration node kind (FunctionLike);
 * registering against ClassLike would incorrectly fire for class-level stacking, which is not a
 * supported use case but also not a method context.
 *
 * @implements Rule<Node>
 */
final class RequestFieldNameRequiredOnMethodRule implements Rule
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
        $fields = AttributeHelpers::attributesNamed(
            AttributeHelpers::getAttributeGroups($node),
            RequestField::class,
        );

        if ($fields === []) {
            return [];
        }

        $errors = [];

        foreach ($fields as $attribute) {
            if (!$this->nameIsMissingOrEmpty($attribute, $scope)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                '#[RequestField] on a method requires a name: — the name cannot be derived from a method target.',
            )
                ->identifier('openapi.requestField.nameRequiredOnMethod')
                ->line($attribute->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * True when `name:` is absent (neither by name nor positionally), explicitly null, or resolves
     * to the empty string. Mirrors the two-condition runtime skip
     * (`$field->name === null || $field->name === ''`) so the static rule catches exactly the same
     * cases the runtime silently drops.
     *
     * `$name` is the first constructor parameter of `RequestField`, so a bare positional first
     * argument (`#[RequestField('foo')]`) counts as providing the name.
     */
    private function nameIsMissingOrEmpty(Node\Attribute $attribute, Scope $scope): bool
    {
        // Check named argument first
        $argument = AttributeHelpers::getArgument($attribute, 'name');

        if ($argument === null) {
            // Fall back to positional first argument (name is parameter #0)
            $argument = array_find(
                $attribute->args,
                static fn(Node\Arg $arg): bool => $arg->name === null,
            );
        }

        // Absent entirely
        if ($argument === null) {
            return true;
        }

        // Explicitly null
        if ($argument->value instanceof Node\Expr\ConstFetch
            && $argument->value->name->toLowerString() === 'null'
        ) {
            return true;
        }

        // Empty string literal
        $values = $scope->getType($argument->value)->getConstantScalarValues();

        return count($values) === 1 && $values[0] === '';
    }
}
