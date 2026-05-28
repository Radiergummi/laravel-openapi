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
use Radiergummi\OpenApi\PhpStan\Rules\HideOnlyExceptRule;

/**
 * @extends RuleTestCase<HideOnlyExceptRule>
 */
final class HideOnlyExceptRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HideOnlyExceptRule();
    }

    public function testFlagsBothOnlyAndExcept(): void
    {
        $this->analyse([__DIR__ . '/data/hide-only-except.php'], [
            [
                '#[Hide] cannot use both only and except — they are mutually exclusive.',
                20,
            ],
        ]);
    }
}
