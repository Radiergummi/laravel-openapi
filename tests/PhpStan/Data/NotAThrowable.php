<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\ExceptionResponse;

#[ExceptionResponse(status: 500, description: 'Plain DTO')]
final class NotAThrowable
{
    public function __construct(public string $reason) {}
}
