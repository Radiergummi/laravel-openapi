<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\Rfc7807Envelope;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

uses()->group('openapi');

it('refs the Problem schema for non-validation responses with application/problem+json', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 404,
        exceptionClass: ModelNotFoundException::class,
        description: 'Resource not found',
    ));

    $media = $response->content[0];
    expect($media->mediaType)->toBe('application/problem+json')
        ->and($media->schema->ref)->toBe($registry->qualifyKey('Problem'));
});

it('refs ValidationProblem for ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: ValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)
        ->toBe($registry->qualifyKey('ValidationProblem'));
});

it('registers both Problem and ValidationProblem component schemas', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $envelope->resolveErrorResponse(new ErrorDescriptor(404, ModelNotFoundException::class, 'Not found'));
    $envelope->resolveErrorResponse(new ErrorDescriptor(422, ValidationException::class, 'Validation failed'));

    expect($registry->hasKey('Problem'))->toBeTrue()
        ->and($registry->hasKey('ValidationProblem'))->toBeTrue();
});
