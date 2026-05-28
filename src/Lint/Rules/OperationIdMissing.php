<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports operations that have no operationId defined.
 *
 * The operationId is used by client generators and documentation tools to identify operations.
 * Every operation should have a unique operationId.
 */
final class OperationIdMissing implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->operationId === null) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no operationId',
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add an operationId to the operation via #[Operation(operationId: "...")].',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.id-missing';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no operationId.';
    }
}
