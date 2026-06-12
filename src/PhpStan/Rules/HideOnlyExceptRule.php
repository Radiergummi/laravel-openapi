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
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;

/**
 * Flags `#[Hide]` usages that set both `only` and `except`. Mirrors {@see ExposeOnlyExceptRule}
 * — the runtime constructor throws `LogicException` when reflected, but only at spec generation.
 *
 * @implements Rule<Node\Attribute>
 */
final class HideOnlyExceptRule implements Rule
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

        if ($node->name->toString() !== Hide::class) {
            return [];
        }

        if (
            AttributeHelpers::argumentIsProvided($node, 'only')
            && AttributeHelpers::argumentIsProvided($node, 'except')
        ) {
            return [
                RuleErrorBuilder::message(
                    '#[Hide] cannot use both only and except — they are mutually exclusive.',
                )->identifier('openapi.hide.onlyAndExcept')->build(),
            ];
        }

        return [];
    }
}
