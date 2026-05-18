<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;

use function sprintf;

/**
 * Reports operations that have no tags assigned.
 *
 * Tags help organize operations in generated documentation and client SDKs.
 * Every operation should have at least one tag.
 */
final class OperationTagMissing implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->tags === []) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no tags',
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add at least one tag to the operation via #[Tag] or #[Operation(tags: [...])].',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.tag-missing';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no tags.';
    }
}
