<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\QueryParameterRule as QueryParameterRuleVisitor;

use function sprintf;

/**
 * Reports array query parameters that do not explicitly set `style` or `explode`.
 */
final class ParameterQueryArrayNoExplode implements Rule, QueryParameterRuleVisitor
{
    public string $id = 'parameter.query-array-no-explode';
    public Severity $severity = Severity::Degraded;
    public string $description = 'Array query parameter is missing explode: true.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Query parameter "%s" on %s %s has an array schema but does not set style or explode',
                $queryParameter->name,
                $operation instanceof OperationNode ? $operation->method->forDisplay() : '(unknown)',
                $operation instanceof OperationNode ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Explicitly set style (e.g. "form") and/or explode (true/false) to avoid ambiguous array serialisation.',
        );
    }



}
