<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\Plugins\QueryBuilder\PhpStan\Rules\QueryBuilderFilterDuplicateRule;

/**
 * @extends RuleTestCase<QueryBuilderFilterDuplicateRule>
 */
final class QueryBuilderFilterDuplicateRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueryBuilderFilterDuplicateRule(Node\FunctionLike::class);
    }

    public function testFlagsRepeatedFilterNames(): void
    {
        $this->analyse([__DIR__ . '/Data/query-builder-filter-duplicate.php'], [
            [
                "#[AllowedFilter('status')] is declared more than once on this target — only the last instance is emitted.",
                16,
            ],
            [
                "#[AllowedFilter('state')] is declared more than once on this target — only the last instance is emitted.",
                20,
            ],
            [
                "#[AllowedFilter('limit')] is declared more than once on this target — only the last instance is emitted.",
                24,
            ],
            [
                "#[AllowedFilter('limit')] is declared more than once on this target — only the last instance is emitted.",
                25,
            ],
        ]);
    }
}
