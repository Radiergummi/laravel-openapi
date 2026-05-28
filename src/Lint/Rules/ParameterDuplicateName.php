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

use function array_count_values;
use function array_map;
use function sprintf;

/**
 * Reports duplicate path parameters within a single operation.
 *
 * The OpenAPI specification requires that path parameter names be unique per operation. This
 * rule detects violations among path parameters only.
 */
final class ParameterDuplicateName implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $names = array_map(static fn($param): string => $param->name, $operation->parameters);

        $counts = array_count_values($names);

        foreach ($counts as $name => $occurrenceCount) {
            if ($occurrenceCount < 2) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Parameter "%s" (in: path) is declared %d times on %s %s',
                    $name,
                    $occurrenceCount,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate parameter declaration; (name, in) must be unique per operation.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.duplicate-name';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two parameters in the same operation share the same name and location.';
    }
}
