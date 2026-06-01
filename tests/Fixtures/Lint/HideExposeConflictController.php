<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;

final class HideExposeConflictController
{
    #[Hide]
    #[Expose]
    public function both(): array
    {
        return [];
    }

    #[Hide(only: ['production'])]
    #[Expose(only: ['production'])]
    public function envOverlap(): array
    {
        return [];
    }

    #[Hide(only: ['production'])]
    #[Expose(only: ['staging'])]
    public function envDisjoint(): array
    {
        return [];
    }
}
