<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

final class InvokableActionController
{
    public function __invoke(): array
    {
        return [];
    }
}
