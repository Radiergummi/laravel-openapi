<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;

#[Hide]
#[Expose]
final class HideExposeConflictClassFixture
{
    public function noop(): void {}
}
