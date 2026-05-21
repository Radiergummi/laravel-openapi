<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Core\Attributes\Expose;

final class VisibilityFixtureController
{
    public function bare(): array
    {
        return [];
    }

    #[Expose]
    public function explicitlyExposed(): array
    {
        return [];
    }

    #[Expose(only: ['staging'])]
    public function exposedInStagingOnly(): array
    {
        return [];
    }
}
