<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\ExampleValueOrFileRule;

/**
 * @extends RuleTestCase<ExampleValueOrFileRule>
 */
final class ExampleValueOrFileRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ExampleValueOrFileRule();
    }

    public function testFlagsMissingOrBothValueAndFile(): void
    {
        $this->analyse([__DIR__ . '/Data/example-value-or-file.php'], [
            [
                '#[Example] requires exactly one of value or file.',
                24,
            ],
            [
                '#[Example] must not set both value and file — they are mutually exclusive.',
                30,
            ],
            [
                '#[ResponseExample] requires exactly one of value or file.',
                36,
            ],
            [
                '#[ResponseExample] must not set both value and file — they are mutually exclusive.',
                42,
            ],
            [
                '#[Example] requires exactly one of value or file.',
                48,
            ],
        ]);
    }
}
