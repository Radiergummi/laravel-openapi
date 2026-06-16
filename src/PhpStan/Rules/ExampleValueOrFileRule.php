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
use Radiergummi\OpenApi\Attributes\Example;
use Radiergummi\OpenApi\Attributes\ResponseExample;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;

/**
 * Flags `#[Example]` and `#[ResponseExample]` that don't set exactly one of `value` or `file`.
 * The constructor throws at runtime; this lifts the check to edit time.
 *
 * @implements Rule<Node\Attribute>
 */
final class ExampleValueOrFileRule implements Rule
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

        $shortName = match ($node->name->toString()) {
            Example::class => 'Example',
            ResponseExample::class => 'ResponseExample',
            default => null,
        };

        if ($shortName === null) {
            return [];
        }

        $hasValue = AttributeHelpers::argumentIsProvided($node, 'value');
        $hasFile = AttributeHelpers::argumentIsProvided($node, 'file');

        if ($hasValue && $hasFile) {
            return [
                RuleErrorBuilder::message(
                    "#[{$shortName}] must not set both value and file — they are mutually exclusive.",
                )->identifier('openapi.example.bothValueAndFile')->build(),
            ];
        }

        if (!$hasValue && !$hasFile) {
            return [
                RuleErrorBuilder::message(
                    "#[{$shortName}] requires exactly one of value or file.",
                )->identifier('openapi.example.missingValueOrFile')->build(),
            ];
        }

        return [];
    }
}
