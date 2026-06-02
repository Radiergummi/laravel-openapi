<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Response;

class DuplicateResponseStatusFixtureController
{
    #[Response(status: 200, description: 'OK')]
    #[Response(status: 200, description: 'OK')]
    public function show(): void {}
}
