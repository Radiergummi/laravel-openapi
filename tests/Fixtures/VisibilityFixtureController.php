<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\Expose;

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
