<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\PublicEndpoint;

class PublicEndpointMwController
{
    #[PublicEndpoint]
    public function publicAction(): void {}

    public function protectedAction(): void {}
}
