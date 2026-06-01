<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope;
use Radiergummi\OpenApi\Core\Envelopes\Rfc7807Envelope;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
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
