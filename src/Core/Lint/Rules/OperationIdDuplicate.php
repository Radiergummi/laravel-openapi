<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\Finalizable;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\Resettable;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;

use function count;
use function sprintf;

/**
 * Reports when multiple operations share the same operationId.
 *
 * The OpenAPI specification requires each operationId to be unique across the entire API. This rule
 * detects duplicates and reports both occurrences.
 */
final class OperationIdDuplicate implements Rule, OperationRuleVisitor, Finalizable, Resettable
{
    /**
     * @var OperationNode[][]
     */
    private array $seen = [];

    #[Override]
    public function reset(): void
    {
        $this->seen = [];
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->operationId !== null) {
            $this->seen[$operation->operationId][] = $operation;
        }

        return [];
    }

    /** @return iterable<Finding> */
    #[Override]
    public function finalize(LintContext $context): iterable
    {
        foreach ($this->seen as $operationId => $nodes) {
            if (count($nodes) < 2) {
                continue;
            }

            foreach ($nodes as $node) {
                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf(
                        'Duplicate operationId "%s" on %s %s (%d occurrences)',
                        $operationId,
                        $node->method,
                        $node->pathUri,
                        count($nodes),
                    ),
                    location: new FindingLocation(routeMethod: $node->method, routeUri: $node->pathUri),
                    fixHint: 'Each operationId must be unique across the entire API.',
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.id-duplicate';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two operations share the same operationId.';
    }
}
