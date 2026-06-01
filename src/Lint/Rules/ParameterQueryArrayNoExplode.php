<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\QueryParameterRule as QueryParameterRuleVisitor;

use function sprintf;

/**
 * Reports query parameters with an array schema that do not explicitly set `style` or `explode`,
 * which can lead to ambiguous serialisation across different clients and servers.
 */
final class ParameterQueryArrayNoExplode implements Rule, QueryParameterRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkQueryParameter(
        QueryParameterNode $queryParameter,
        LintContext $context,
    ): iterable {
        if ($queryParameter->type !== 'array') {
            return;
        }

        if ($queryParameter->style !== null || $queryParameter->explode !== null) {
            return;
        }

        $operation = $queryParameter->parent();

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Query parameter "%s" on %s %s has an array schema but does not set style or explode',
                $queryParameter->name,
                $operation instanceof OperationNode ? $operation->method : '(unknown)',
                $operation instanceof OperationNode ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Explicitly set style (e.g. "form") and/or explode (true/false) to avoid ambiguous array serialisation.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.query-array-no-explode';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Array query parameter is missing explode: true.';
    }
}
