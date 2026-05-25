<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Errors;

use Illuminate\Validation\ValidationException;

/**
 * User-defined subclass of a framework exception.
 * Used to test that error envelope handlers match exception subclasses.
 */
class CustomValidationException extends ValidationException
{
    public function __construct()
    {
        // Bypass parent constructor (it requires a Validator instance).
    }
}
