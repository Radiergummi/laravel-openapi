<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;

uses()->group('openapi');

it('exposes status, exception class, and description', function (): void {
    $descriptor = new ErrorDescriptor(
        status: 422,
        exceptionClass: ValidationException::class,
        description: 'Validation failed',
    );

    expect($descriptor->status)->toBe(422);
    expect($descriptor->exceptionClass)->toBe(ValidationException::class);
    expect($descriptor->description)->toBe('Validation failed');
});

it('accepts a null exception class', function (): void {
    $descriptor = new ErrorDescriptor(
        status: 401,
        exceptionClass: null,
        description: 'Unauthenticated',
    );

    expect($descriptor->exceptionClass)->toBeNull();
});
