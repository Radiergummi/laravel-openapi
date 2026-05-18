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

class PublicEndpointMwController
{
    #[PublicEndpoint]
    public function publicAction(): void {}

    public function protectedAction(): void {}
}
