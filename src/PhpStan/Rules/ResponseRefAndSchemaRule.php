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
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function assert;

/**
 * Flags `#[Response]` usages that set both `ref` and `schema`. `schema` wins at runtime so the
 * `ref` is silently ignored — almost always a copy-paste mistake or an unfinished migration from
 * a literal schema to a class reference (or vice versa). The constructor accepts both, so the
 * conflict is only visible once the generator picks the winner.
 *
 * @implements Rule<Node\Attribute>
 */
final class ResponseRefAndSchemaRule implements Rule
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

        if ($node->name->toString() !== Response::class) {
            return [];
        }

        if (
            !AttributeHelpers::argumentIsProvided($node, 'ref')
            || !AttributeHelpers::argumentIsProvided($node, 'schema')
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                '#[Response] must not set both ref and schema — schema wins and ref is silently dropped.',
            )->identifier('openapi.response.refAndSchema')->build(),
        ];
    }
}
