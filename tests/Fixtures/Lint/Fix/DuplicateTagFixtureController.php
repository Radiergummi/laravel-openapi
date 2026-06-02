<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Tag;

class DuplicateTagFixtureController
{
    #[Tag('users')]
    #[Tag('users')]
    public function index(): void {}
}
