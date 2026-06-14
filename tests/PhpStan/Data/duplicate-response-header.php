<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\ResponseHeader;

final class DuplicateResponseHeaderFixture
{
    #[ResponseHeader(name: 'X-Request-Id')]
    #[ResponseHeader(name: 'X-Rate-Limit')]
    public function distinctNames(): void {}

    #[ResponseHeader(name: 'X-Request-Id', status: 200)]
    #[ResponseHeader(name: 'X-Request-Id', status: 404)]
    public function sameNameDifferentStatuses(): void {}

    #[ResponseHeader(name: 'X-Request-Id')]
    #[ResponseHeader(name: 'X-Request-Id')]
    public function duplicateDefaultStatus(): void {}

    #[ResponseHeader(name: 'X-Trace', status: 500)]
    #[ResponseHeader(name: 'X-Trace', status: 500)]
    #[ResponseHeader(name: 'X-Trace', status: 500)]
    public function tripleDuplicate(): void {}

    #[ResponseHeader(name: 'X-Correlation-Id')]
    #[ResponseHeader(name: 'x-correlation-id')]
    public function caseInsensitiveDuplicate(): void {}

    #[ResponseHeader('X-Positional')]
    #[ResponseHeader('X-Positional')]
    public function positionalNameDuplicate(): void {}

    #[ResponseHeader('X-Pos-Status', 404)]
    #[ResponseHeader('X-Pos-Status', 500)]
    public function positionalDifferentStatuses(): void {}

    #[ResponseHeader('X-Pos-Status', 404)]
    #[ResponseHeader('X-Pos-Status', 404)]
    public function positionalSameStatus(): void {}
}
