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
use Radiergummi\OpenApi\PhpStan\Rules\PublicEndpointSecurityConflictRule;

/**
 * @extends RuleTestCase<PublicEndpointSecurityConflictRule>
 */
final class PublicEndpointSecurityConflictRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PublicEndpointSecurityConflictRule(Node\FunctionLike::class);
    }

    public function testFlagsMethodLevelConflicts(): void
    {
        $this->analyse([__DIR__ . '/Data/public-endpoint-security-conflict.php'], [
            [
                '#[PublicEndpoint] and #[Security] cannot coexist on the same target — they contradict each other.',
                18,
            ],
        ]);
    }
}
