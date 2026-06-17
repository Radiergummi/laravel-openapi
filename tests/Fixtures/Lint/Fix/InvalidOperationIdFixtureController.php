<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\Operation;

class InvalidOperationIdFixtureController
{
    #[Operation(operationId: 'list users!')]
    public function withSpaces(): void {}

    #[Operation(operationId: '123-users')]
    public function withLeadingDigits(): void {}
}
