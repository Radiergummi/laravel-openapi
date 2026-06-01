<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Link;

class DuplicateLinkNameFixtureController
{
    #[Link(name: 'self', operationId: 'self.show')]
    #[Link(name: 'self', operationId: 'self.show')]
    public function show(): void {}
}
