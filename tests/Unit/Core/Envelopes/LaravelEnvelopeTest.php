<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\LaravelEnvelope;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

uses()->group('openapi');

it('refs the Error schema for non-validation responses', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(
        new ErrorDescriptor(
            status: 401,
            exceptionClass: AuthenticationException::class,
            description: 'Unauthenticated',
        ),
    );

    expect($response->content)->toHaveCount(1);
    $media = $response->content[0];
    expect($media)->toBeInstanceOf(OA\MediaType::class);
    expect($media->mediaType)->toBe('application/json');
    expect($media->schema->ref)->toBe($registry->qualifyKey('Error'));
});

it('refs the ValidationError schema when the exception is ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(
        new ErrorDescriptor(
            status: 422,
            exceptionClass: ValidationException::class,
            description: 'Validation failed',
        ),
    );

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('falls back to status 422 when no exception class is set', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(
        new ErrorDescriptor(
            status: 422,
            exceptionClass: null,
            description: 'Validation failed',
        ),
    );

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('registers both Error and ValidationError component schemas idempotently', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $envelope->resolveErrorResponse(new ErrorDescriptor(401, AuthenticationException::class, 'Unauthenticated'));
    $envelope->resolveErrorResponse(new ErrorDescriptor(401, AuthenticationException::class, 'Unauthenticated'));
    $envelope->resolveErrorResponse(new ErrorDescriptor(422, ValidationException::class, 'Validation failed'));

    expect($registry->hasKey('Error'))->toBeTrue();
    expect($registry->hasKey('ValidationError'))->toBeTrue();
});
