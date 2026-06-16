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
use Radiergummi\OpenApi\Attributes\Link;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;

/**
 * Flags `#[Link]` usages where exactly one of `operationId` / `operationRef` is not set.
 * The attribute constructor does not guard the pair; this is the earliest point it can be caught.
 * PHPStan identifiers use camelCase because dashes are not allowed.
 *
 * @implements Rule<Node\Attribute>
 */
final class LinkOperationTargetRule implements Rule
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
