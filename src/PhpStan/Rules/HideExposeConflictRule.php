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
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

/**
 * Flags unconditional `#[Hide]` + `#[Expose]` on the same target (neither uses `only`/`except`).
 * Env-scoped pairs are not flagged here; the runtime lint rule owns that case.
 *
 * One service is registered per declaration node kind; the node type is injected via constructor.
 *
 * @implements Rule<Node>
 */
final class HideExposeConflictRule implements Rule
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
        $attributesByFqn = AttributeHelpers::attributesByFqn(AttributeHelpers::getAttributeGroups($node));
        $hideAttrs = $attributesByFqn[Hide::class] ?? [];
        $exposeAttrs = $attributesByFqn[Expose::class] ?? [];

        if ($hideAttrs === [] || $exposeAttrs === []) {
            return [];
        }

        $unconditionalHide = self::firstUnconditional($hideAttrs);
        $unconditionalExpose = self::firstUnconditional($exposeAttrs);

        if ($unconditionalHide === null || $unconditionalExpose === null) {
            return [];
        }

        $line = min(
            $unconditionalHide->getStartLine(),
            $unconditionalExpose->getStartLine(),
        );

        return [
            RuleErrorBuilder::message(
                'Unconditional #[Hide] and #[Expose] cannot coexist on the same target — they contradict each other in every environment.',
            )
                ->identifier('openapi.visibility.hideExposeConflict')
                ->line($line)
                ->build(),
        ];
    }

    /**
     * @param list<Node\Attribute> $attributes
     */
    private static function firstUnconditional(array $attributes): ?Node\Attribute
    {
        return array_find($attributes, fn($attribute)
            => !AttributeHelpers::argumentIsProvided($attribute, 'only')
            && !AttributeHelpers::argumentIsProvided($attribute, 'except'));
    }
}
