<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\FieldRangeOrderingRule;

/**
 * @extends RuleTestCase<FieldRangeOrderingRule>
 */
final class FieldRangeOrderingRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new FieldRangeOrderingRule();
    }

    public function testFlagsInvertedRanges(): void
    {
        $this->analyse([__DIR__ . '/Data/field-range-ordering.php'], [
            [
                'Field attribute has minimum (100) greater than maximum (10) — the resulting range is empty.',
                13,
            ],
            [
                'Field attribute has minimum (2.5) greater than maximum (1.5) — the resulting range is empty.',
                14,
            ],
            [
                'Field attribute has minLength (50) greater than maxLength (5) — the resulting range is empty.',
                15,
            ],
            [
                'Field attribute has minItems (9) greater than maxItems (3) — the resulting range is empty.',
                16,
            ],
            [
                'Field attribute has minimum (10) greater than maximum (1) — the resulting range is empty.',
                17,
            ],
            [
                'Field attribute has minLength (5) greater than maxLength (1) — the resulting range is empty.',
                17,
            ],
            [
                'Field attribute has minLength (100) greater than maxLength (10) — the resulting range is empty.',
                21,
            ],
            [
                'Field attribute has minItems (5) greater than maxItems (2) — the resulting range is empty.',
                23,
            ],
            [
                'Field attribute has minimum (10) greater than maximum (1) — the resulting range is empty.',
                32,
            ],
        ]);
    }
}
