<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

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
    protected function getRule(): Rule
    {
        return new ExposeOnlyExceptRule();
    }

    public function testFlagsBothOnlyAndExcept(): void
    {
        $this->analyse([__DIR__ . '/data/expose-only-except.php'], [
            [
                '#[Expose] cannot use both only and except — they are mutually exclusive.',
                20,
            ],
        ]);
    }
}
