<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Tag;

/**
 * Fixture with duplicate #[Tag] attributes in a single attribute group (`#[Tag, Tag]`), exercising
 * the byte-precise shared-group removal in RemoveAttributeFixer::buildOperation (comma-swallowing),
 * as opposed to the whole-line RemoveLines path used by separate-line duplicates.
 */
class SharedGroupTagFixtureController
{
    #[Tag('users'), Tag('users')]
    public function index(): void {}
}
