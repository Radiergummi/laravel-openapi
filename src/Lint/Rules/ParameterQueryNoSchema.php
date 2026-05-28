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
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\QueryParameterRule as QueryParameterRuleVisitor;

use function sprintf;

/**
 * Reports query parameters that have no schema defined.
 *
 * Query parameters should declare a schema so that clients and documentation tools know the
 * expected type and format of the parameter.
 */
final class ParameterQueryNoSchema implements Rule, QueryParameterRuleVisitor
{
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Query parameter "%s" on %s %s has no schema',
                $queryParameter->name,
                $operation instanceof OperationNode ? $operation->method : '(unknown)',
                $operation instanceof OperationNode ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a schema to the query parameter to declare its type and format.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.query-no-schema';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Query parameter has no schema.';
    }
}
