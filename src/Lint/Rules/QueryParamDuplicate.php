<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function array_count_values;
use function array_map;
use function sprintf;

/**
 * Reports duplicate query parameters within a single operation.
 *
 * Query parameter names must be unique per operation.
 */
final class QueryParamDuplicate implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $names = array_map(static fn($queryParameter): string => $queryParameter->name, $operation->queryParameters);

        $counts = array_count_values($names);

        foreach ($counts as $name => $occurrenceCount) {
            if ($occurrenceCount < 2) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Query parameter "%s" is declared %d times on %s %s',
                    $name,
                    $occurrenceCount,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate query parameter declaration; names must be unique per operation.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'queryparam.duplicate';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two #[QueryParam] attributes on the same controller/method share the same name.';
    }
}
