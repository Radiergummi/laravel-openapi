<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

use function sprintf;

/**
 * Flags an `#[AllowedFilter]` declared with no `type` — the filter parameter
 * falls back to `string`, which may misrepresent the accepted value.
 */
final readonly class QueryBuilderFilterTypeMissing implements Rule, OperationRule
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;

        if ($operation->webhook || $descriptor === null) {
            return;
        }

        foreach ($descriptor->actionAttributes(AllowedFilter::class) as $attribute) {
            $filter = $attribute->newInstance();

            if ($filter->type !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[AllowedFilter(\'%s\')] on %s %s has no type — the filter parameter defaults to string',
                    $filter->name,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add a type: to #[AllowedFilter] (\'string\', \'integer\', \'boolean\', …).',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'query-builder.filter-type-missing';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'An #[AllowedFilter] is declared without an explicit value type.';
    }
}
