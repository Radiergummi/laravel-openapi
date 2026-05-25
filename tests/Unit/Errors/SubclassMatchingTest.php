<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Errors\LaravelEnvelope;
use Radiergummi\OpenApi\Core\Errors\Rfc7807Envelope;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Errors\CustomValidationException;

uses()->group('openapi');

it('LaravelEnvelope matches subclasses of ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: CustomValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('Rfc7807Envelope matches subclasses of ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: CustomValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationProblem'));
});
