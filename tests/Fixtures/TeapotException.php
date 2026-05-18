<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

// Fixture: exception carrying a #[Throws] attribute for extractor tests.

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Core\Attributes\ExceptionResponse;
use RuntimeException;

#[ExceptionResponse(status: 418, description: "I'm a teapot")]
class TeapotException extends RuntimeException {}
