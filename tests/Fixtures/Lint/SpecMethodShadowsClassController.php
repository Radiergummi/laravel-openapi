<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\Spec;

/**
 * Controller where the method-level #[Spec] shadows the class-level one.
 * Class carries an undeclared spec ('ghost'); method carries a declared one ('v2').
 * SpecResolver discards the class attribute when the method carries any #[Spec].
 */
#[Spec('ghost')]
final class SpecMethodShadowsClassController
{
    #[Spec('v2')]
    public function handle(): void {}
}
