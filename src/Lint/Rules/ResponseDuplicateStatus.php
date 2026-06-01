<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function array_count_values;
use function array_map;
use function sprintf;

final class ResponseDuplicateStatus implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->responses === []) {
            return;
        }

        $statusCodes = array_map(
            static fn(ResponseNode $r): string => (string) $r->statusCode,
            $operation->responses,
        );

        $counts = array_count_values($statusCodes);

        foreach ($counts as $statusCode => $count) {
            if ($count <= 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'HTTP status %s is declared %d times on %s %s',
                    $statusCode,
                    $count,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate #[Response] attribute or change the status code.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'response.duplicate-status';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two responses on the same operation share the same status code.';
    }
}
