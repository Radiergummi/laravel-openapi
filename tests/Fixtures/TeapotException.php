<?php

// Fixture: exception carrying a #[Throws] attribute for extractor tests.

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\ExceptionResponse;
use RuntimeException;

#[ExceptionResponse(status: 418, description: "I'm a teapot")]
class TeapotException extends RuntimeException {}
