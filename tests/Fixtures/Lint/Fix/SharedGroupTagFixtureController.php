<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Tag;

/**
 * Fixture with duplicate #[Tag] attributes in a single attribute group (`#[Tag, Tag]`), exercising
 * shared-group removal (the printer drops the attribute and re-renders the group, swallowing the
 * comma), as opposed to the whole-line removal used for separate-line duplicates.
 */
class SharedGroupTagFixtureController
{
    #[Tag('users'), Tag('users')]
    public function index(): void {}
}
