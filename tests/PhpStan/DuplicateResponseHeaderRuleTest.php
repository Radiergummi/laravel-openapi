<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\DuplicateResponseHeaderRule;

/**
 * @extends RuleTestCase<DuplicateResponseHeaderRule>
 */
final class DuplicateResponseHeaderRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DuplicateResponseHeaderRule(Node\FunctionLike::class);
    }

    public function testFlagsRepeatedStatusNamePairs(): void
    {
        $this->analyse([__DIR__ . '/Data/duplicate-response-header.php'], [
            [
                "#[ResponseHeader] 'X-Request-Id' for status 200 is declared more than once on this target — the duplicate is silently dropped.",
                20,
            ],
            [
                "#[ResponseHeader] 'X-Trace' for status 500 is declared more than once on this target — the duplicate is silently dropped.",
                24,
            ],
            [
                "#[ResponseHeader] 'X-Trace' for status 500 is declared more than once on this target — the duplicate is silently dropped.",
                25,
            ],
            [
                "#[ResponseHeader] 'x-correlation-id' for status 200 is declared more than once on this target — the duplicate is silently dropped.",
                29,
            ],
        ]);
    }
}
