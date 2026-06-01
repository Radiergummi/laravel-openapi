<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\QueryParamRequiredWithDefaultRule;

/**
 * @extends RuleTestCase<QueryParamRequiredWithDefaultRule>
 */
final class QueryParamRequiredWithDefaultRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueryParamRequiredWithDefaultRule();
    }

    public function testFlagsRequiredWithDefault(): void
    {
        $this->analyse([__DIR__ . '/Data/queryparam-required-with-default.php'], [
            [
                '#[QueryParam] sets required: true together with a default value — the default makes the parameter implicitly optional. Drop one.',
                13,
            ],
            [
                '#[QueryParam] sets required: true together with a default value — the default makes the parameter implicitly optional. Drop one.',
                14,
            ],
        ]);
    }
}
