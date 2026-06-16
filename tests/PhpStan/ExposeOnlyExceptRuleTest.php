<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\ExposeOnlyExceptRule;

/**
 * @extends RuleTestCase<ExposeOnlyExceptRule>
 */
final class ExposeOnlyExceptRuleTest extends RuleTestCase
{
    public function testFlagsBothOnlyAndExcept(): void
    {
        $this->analyse([__DIR__ . '/Data/expose-only-except.php'], [
            [
                '#[Expose] cannot use both only and except — they are mutually exclusive.',
                20,
            ],
        ]);
    }

    protected function getRule(): Rule
    {
        return new ExposeOnlyExceptRule();
    }
}
