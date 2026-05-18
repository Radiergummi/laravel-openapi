<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\PublicEndpoint;

/**
 * Fixture controller for the OperationSecurityMissing rule tests.
 */
class OperationSecurityMissingController
{
    /** Route has auth middleware, no #[PublicEndpoint] — rule MUST fire. */
    public function protectedAction(): void {}

    /** Route has no auth middleware — rule must NOT fire. */
    public function publicAction(): void {}

    /** Route has auth middleware but is marked public — rule must NOT fire. */
    #[PublicEndpoint]
    public function explicitlyPublicAction(): void {}
}
