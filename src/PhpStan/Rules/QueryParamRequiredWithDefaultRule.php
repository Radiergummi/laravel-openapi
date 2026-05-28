<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Rules;

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
 * Flags `#[QueryParam(required: true, default: …)]`. OpenAPI parameters with a `default` are
 * implicitly optional — when the client omits the value, the server falls back to the default —
 * so `required: true` contradicts the very fact a default exists. Tooling routinely picks the
 * winner inconsistently (some flag the parameter as required and ignore the default; some accept
 * the default and treat the parameter as optional), making this an authoring smell worth catching
 * statically.
 *
 * Only fires when `required` resolves to constant `true` and `default` is provided with a non-null
 * literal value. Literal `null` is treated as absent — it matches the constructor default and
 * carries no semantic intent.
 *
 * @implements Rule<Node\Attribute>
 */
final class QueryParamRequiredWithDefaultRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Attribute::class;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
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
