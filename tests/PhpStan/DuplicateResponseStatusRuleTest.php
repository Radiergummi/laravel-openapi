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
use Radiergummi\OpenApi\PhpStan\Rules\DuplicateResponseStatusRule;

/**
 * @extends RuleTestCase<DuplicateResponseStatusRule>
 */
final class DuplicateResponseStatusRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DuplicateResponseStatusRule(Node\FunctionLike::class);
    }

    public function testFlagsRepeatedStatusCodes(): void
    {
        $this->analyse([__DIR__ . '/data/duplicate-response-status.php'], [
            [
                '#[Response] with status 404 is declared more than once on this target — duplicate status codes are silently dropped.',
                16,
            ],
            [
                '#[Response] with status 500 is declared more than once on this target — duplicate status codes are silently dropped.',
                20,
            ],
            [
                '#[Response] with status 500 is declared more than once on this target — duplicate status codes are silently dropped.',
                21,
            ],
        ]);
    }
}
