<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\ExceptionResponse;
use RuntimeException;

#[ExceptionResponse(status: 409, description: 'Duplicate')]
final class ValidThrowable extends RuntimeException {}
