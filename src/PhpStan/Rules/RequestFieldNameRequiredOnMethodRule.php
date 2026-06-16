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
 * Flags `#[RequestField]` on methods/functions that omit `name:`. On a method the name cannot be
 * derived from the target; without it the runtime silently drops the field.
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
     * True when `name:` is absent, null, or empty. Mirrors the runtime skip condition
     * (`$field->name === null || $field->name === ''`) exactly.
     */
    private function nameIsMissingOrEmpty(Node\Attribute $attribute, Scope $scope): bool
    {
        $argument = AttributeHelpers::getArgument($attribute, 'name');

        if ($argument === null) {
            return true;
        }

        if ($argument->value instanceof Node\Expr\ConstFetch
            && $argument->value->name->toLowerString() === 'null'
        ) {
            return true;
        }

        $values = $scope->getType($argument->value)->getConstantScalarValues();

        return count($values) === 1 && $values[0] === '';
    }
}
