<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;

final class HideExposeConflictFixture
{
    #[Hide]
    public function hiddenOnly(): void {}

    #[Expose]
    public function exposedOnly(): void {}

    #[Hide]
    #[Expose]
    public function conflicting(): void {}

    #[Hide(only: ['production']), Expose(only: ['local'])]
    public function conflictingSameGroup(): void {}
}
