<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\Finalizable;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\Resettable;

use function count;
use function sprintf;

/**
 * Reports when multiple operations share the same operationId.
 */
final class OperationIdDuplicate implements Rule, OperationRuleVisitor, Finalizable, Resettable
{
    public string $id = 'operation.id-duplicate';
    public Severity $severity = Severity::Broken;
    public string $description = 'Two operations share the same operationId.';

    /** @var OperationNode[][] */
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
                    ruleId: $this->id,
                    severity: $this->severity,
                    message: sprintf(
                        'Duplicate operationId "%s" on %s %s (%d occurrences)',
                        $operationId,
                        $node->method->forDisplay(),
                        $node->pathUri,
                        count($nodes),
                    ),
                    location: new FindingLocation(routeMethod: $node->method, routeUri: $node->pathUri),
                    fixHint: 'Each operationId must be unique across the entire API.',
                );
            }
        }
    }



}
