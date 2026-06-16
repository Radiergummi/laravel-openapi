<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Radiergummi\OpenApi\PhpStan\Rules\RequestFieldNameRequiredOnMethodRule;

/**
 * @extends RuleTestCase<RequestFieldNameRequiredOnMethodRule>
 */
final class RequestFieldNameRequiredOnMethodRuleTest extends RuleTestCase
{
    public function testFlagsMissingNameOnMethod(): void
    {
        $this->analyse([__DIR__ . '/Data/requestfield-name-required-on-method.php'], [
            [
                '#[RequestField] on a method requires a name: — the name cannot be derived from a method target.',
                20,
            ],
            [
                '#[RequestField] on a method requires a name: — the name cannot be derived from a method target.',
                24,
            ],
        ]);
    }

    protected function getRule(): Rule
    {
        return new RequestFieldNameRequiredOnMethodRule(Node\FunctionLike::class);
    }
}
