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
use Radiergummi\OpenApi\Attributes\Link;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;

/**
 * Flags `#[Link]` usages that don't name exactly one operation target. The attribute requires
 * precisely one of `operationId` or `operationRef`; both or neither is invalid.
 *
 * This mirrors the `link.both-operation-id-and-ref` / `link.neither-operation-id-nor-ref` lint
 * rules, moving the same check to edit time. The attribute constructor itself does not guard the
 * pair, so this is the earliest point it can be caught. PHPStan identifiers can't contain dashes,
 * so the static counterparts use camelCase (`openapi.link.bothOperationTargets`,
 * `openapi.link.missingOperationTarget`).
 *
 * @implements Rule<Node\Attribute>
 */
final class LinkOperationTargetRule implements Rule
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

        if ($node->name->toString() !== Link::class) {
            return [];
        }

        $hasOperationId = AttributeHelpers::argumentIsProvided($node, 'operationId');
        $hasOperationRef = AttributeHelpers::argumentIsProvided($node, 'operationRef');

        if ($hasOperationId && $hasOperationRef) {
            return [
                RuleErrorBuilder::message(
                    '#[Link] must not set both operationId and operationRef — they are mutually exclusive.',
                )->identifier('openapi.link.bothOperationTargets')->build(),
            ];
        }

        if (!$hasOperationId && !$hasOperationRef) {
            return [
                RuleErrorBuilder::message(
                    '#[Link] requires exactly one of operationId or operationRef.',
                )->identifier('openapi.link.missingOperationTarget')->build(),
            ];
        }

        return [];
    }
}
