<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\HideExposeConflictRule;

/**
 * @extends RuleTestCase<HideExposeConflictRule>
 */
final class HideExposeConflictOnClassRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HideExposeConflictRule(Node\Stmt\ClassLike::class);
    }

    public function testFlagsClassLevelConflicts(): void
    {
        $this->analyse([__DIR__ . '/Data/hide-expose-conflict-class.php'], [
            [
                'Unconditional #[Hide] and #[Expose] cannot coexist on the same target — they contradict each other in every environment.',
                10,
            ],
        ]);
    }
}
