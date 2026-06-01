<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;

final class VisibilityNoOpController
{
    #[Expose]
    public function exposeInPublic(): array
    {
        return [];
    }

    #[Expose(only: ['staging'])]
    public function envScopedExposeInPublic(): array
    {
        return [];
    }

    #[Hide]
    public function hideInHidden(): array
    {
        return [];
    }
}
