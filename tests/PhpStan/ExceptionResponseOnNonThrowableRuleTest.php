<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\ExceptionResponseOnNonThrowableRule;

/**
 * @extends RuleTestCase<ExceptionResponseOnNonThrowableRule>
 */
final class ExceptionResponseOnNonThrowableRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ExceptionResponseOnNonThrowableRule(self::createReflectionProvider());
    }

    public function testFlagsNonThrowableClasses(): void
    {
        $this->analyse(
            [
                __DIR__ . '/Data/NotAThrowable.php',
                __DIR__ . '/Data/ValidThrowable.php',
            ],
            [
                [
                    '#[ExceptionResponse] is attached to Radiergummi\OpenApi\Tests\PhpStan\Data\NotAThrowable, which does not implement Throwable — the attribute will be silently ignored.',
                    9,
                ],
            ],
        );
    }
}
