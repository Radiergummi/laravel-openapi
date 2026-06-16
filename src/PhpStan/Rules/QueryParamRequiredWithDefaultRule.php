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
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;
use function count;

/**
 * Flags `#[QueryParam(required: true, default: …)]`. A parameter with a default is implicitly
 * optional; combining it with `required: true` is contradictory and handled inconsistently by
 * tooling. Only fires when `required` is constant `true` and `default` is a non-null literal.
 *
 * @implements Rule<Node\Attribute>
 */
final class QueryParamRequiredWithDefaultRule implements Rule
{
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

        if ($node->name->toString() !== QueryParam::class) {
            return [];
        }

        $requiredArg = AttributeHelpers::getArgument($node, 'required');

        if ($requiredArg === null) {
            return [];
        }

        $requiredValues = $scope->getType($requiredArg->value)->getConstantScalarValues();

        if (count($requiredValues) !== 1 || $requiredValues[0] !== true) {
            return [];
        }

        if (!AttributeHelpers::argumentIsProvided($node, 'default')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                '#[QueryParam] sets required: true together with a default value — the default makes the parameter implicitly optional. Drop one.',
            )->identifier('openapi.queryParam.requiredWithDefault')->build(),
        ];
    }
}
