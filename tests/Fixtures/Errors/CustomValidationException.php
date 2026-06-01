<?php

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
