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
use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;

/**
 * Flags `#[Expose]` usages that set both `only` and `except`. The attribute constructor itself
 * throws `LogicException` at runtime, but only when reflection reads the attribute — which is at
 * spec generation, not test time. This lifts the check to edit time.
 *
 * @implements Rule<Node\Attribute>
 */
final class ExposeOnlyExceptRule implements Rule
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

        if ($node->name->toString() !== Expose::class) {
            return [];
        }

        if (
            AttributeHelpers::argumentIsProvided($node, 'only')
            && AttributeHelpers::argumentIsProvided($node, 'except')
        ) {
            return [
                RuleErrorBuilder::message(
                    '#[Expose] cannot use both only and except — they are mutually exclusive.',
                )->identifier('openapi.expose.onlyAndExcept')->build(),
            ];
        }

        return [];
    }
}
