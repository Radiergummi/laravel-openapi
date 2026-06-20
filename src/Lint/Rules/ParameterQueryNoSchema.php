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

/** Reports query parameters that have no schema defined. */
final class ParameterQueryNoSchema implements Rule, QueryParameterRuleVisitor
{
    public string $id = 'parameter.query-no-schema';
    public Severity $severity = Severity::Broken;
    public string $description = 'Query parameter has no schema.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkQueryParameter(
        QueryParameterNode $queryParameter,
        LintContext $context,
    ): iterable {
        if ($queryParameter->hasSchema) {
            return;
        }

        $operation = $queryParameter->parent();

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Query parameter "%s" on %s %s has no schema',
                $queryParameter->name,
                $operation instanceof OperationNode ? $operation->method->forDisplay() : '(unknown)',
                $operation instanceof OperationNode ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a schema to the query parameter to declare its type and format.',
        );
    }



}
