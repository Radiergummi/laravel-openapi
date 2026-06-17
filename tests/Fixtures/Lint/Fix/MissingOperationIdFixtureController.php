<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Operation;

class MissingOperationIdFixtureController
{
    public function withoutAttribute(): void {}

    #[Operation(summary: 'List users')]
    public function withAttribute(): void {}
}
