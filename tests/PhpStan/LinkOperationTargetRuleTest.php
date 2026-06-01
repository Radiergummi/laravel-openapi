<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\LinkOperationTargetRule;

/**
 * @extends RuleTestCase<LinkOperationTargetRule>
 */
final class LinkOperationTargetRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new LinkOperationTargetRule();
    }

    public function testFlagsMissingOrBothOperationTargets(): void
    {
        $this->analyse([__DIR__ . '/Data/link-operation-target.php'], [
            [
                '#[Link] requires exactly one of operationId or operationRef.',
                23,
            ],
            [
                '#[Link] must not set both operationId and operationRef — they are mutually exclusive.',
                29,
            ],
            [
                '#[Link] requires exactly one of operationId or operationRef.',
                35,
            ],
        ]);
    }
}
