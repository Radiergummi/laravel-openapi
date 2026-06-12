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
 * Flags declarations that carry an *unconditional* `#[Hide]` together with an *unconditional*
 * `#[Expose]` — i.e. neither attribute uses `only` or `except`. That's an always-true contradiction
 * the runtime cannot reconcile no matter which environment it runs in.
 *
 * Env-scoped pairs (one or both carry `only`/`except`) are NOT flagged here: their resolution
 * depends on the active environment, which static analysis can't see. The runtime lint rule
 * `visibility.hide-expose-conflict` is environment-aware and owns that case.
 *
 * One service is registered per declaration node kind (FunctionLike / ClassLike) — the node type
 * is passed via constructor and returned from {@see getNodeType()}.
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
     * Returns the first attribute in the list that has neither `only` nor `except` set, or null
     * if every attribute is env-scoped. Env-scoped attributes are left to the runtime lint rule.
     *
     * @param list<Node\Attribute> $attributes
     */
    private static function firstUnconditional(array $attributes): ?Node\Attribute
    {
        return array_find($attributes, fn($attribute)
            => !AttributeHelpers::argumentIsProvided($attribute, 'only')
            && !AttributeHelpers::argumentIsProvided($attribute, 'except'));
    }
}
