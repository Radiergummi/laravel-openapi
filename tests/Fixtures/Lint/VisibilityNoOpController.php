<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;

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
