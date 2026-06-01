<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\PublicEndpoint;

#[PublicEndpoint]
class PublicEndpointMwClassController
{
    public function index(): void {}
}
