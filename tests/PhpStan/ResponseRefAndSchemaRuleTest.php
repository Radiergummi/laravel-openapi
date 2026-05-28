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
use Radiergummi\OpenApi\PhpStan\Rules\ResponseRefAndSchemaRule;

/**
 * @extends RuleTestCase<ResponseRefAndSchemaRule>
 */
final class ResponseRefAndSchemaRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ResponseRefAndSchemaRule();
    }

    public function testFlagsBothRefAndSchema(): void
    {
        $this->analyse([__DIR__ . '/Data/response-ref-and-schema.php'], [
            [
                '#[Response] must not set both ref and schema — schema wins and ref is silently dropped.',
                29,
            ],
        ]);
    }
}
