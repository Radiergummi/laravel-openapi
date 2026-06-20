<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

use function sprintf;

/**
 * Flags an `#[AllowedFilter]` declared with no `type`; the filter parameter falls back to
 * `string`, which may misrepresent the accepted value.
 */
final class QueryBuilderFilterTypeMissing implements Rule, OperationRule
{
    public string $id = 'query-builder.filter-type-missing';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'An #[AllowedFilter] is declared without an explicit value type.';

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
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    '#[AllowedFilter(\'%s\')] on %s %s has no type — the filter parameter defaults to string',
                    $filter->name,
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add a type: to #[AllowedFilter] (\'string\', \'integer\', \'boolean\', …).',
            );
        }
    }



}
