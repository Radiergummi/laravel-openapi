<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\HideExposeConflictRule;

/**
 * @extends RuleTestCase<HideExposeConflictRule>
 */
final class HideExposeConflictRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HideExposeConflictRule(Node\FunctionLike::class);
    }

    public function testFlagsUnconditionalMethodLevelConflicts(): void
    {
        $this->analyse([__DIR__ . '/Data/hide-expose-conflict.php'], [
            [
                'Unconditional #[Hide] and #[Expose] cannot coexist on the same target — they contradict each other in every environment.',
                18,
            ],
        ]);
    }
}
