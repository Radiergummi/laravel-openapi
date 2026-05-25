<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Errors\ErrorResponse;
use Radiergummi\OpenApi\Core\Errors\LaravelEnvelope;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;

uses()->group('openapi');

it('falls through to the next resolver when one returns null', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $passthrough = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
        {
            return null;
        }
    };

    $resolvers = [$passthrough, $envelope];

    $body = null;

    foreach ($resolvers as $resolver) {
        $body = $resolver->resolveErrorResponse(new ErrorDescriptor(
            status: 422,
            exceptionClass: ValidationException::class,
            description: 'Validation failed',
        ));

        if ($body !== null) {
            break;
        }
    }

    expect($body)->not->toBeNull();
    expect($body->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('short-circuits on the first non-null result', function (): void {
    $custom = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            return new ErrorResponse(
                content: [new OA\MediaType(['mediaType' => 'application/custom'])],
            );
        }
    };

    $passthrough = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
        {
            return null;
        }
    };

    $resolvers = [$custom, $passthrough];

    $body = null;

    foreach ($resolvers as $resolver) {
        $body = $resolver->resolveErrorResponse(new ErrorDescriptor(401, null, 'Unauthenticated'));

        if ($body !== null) {
            break;
        }
    }

    expect($body)->not->toBeNull();
    expect($body->content[0]->mediaType)->toBe('application/custom');
});

it('honors ErrorResponse::bodyless() as a claim with no body', function (): void {
    $custom = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            return ErrorResponse::bodyless();
        }
    };

    $body = $custom->resolveErrorResponse(new ErrorDescriptor(500, null, 'Server error'));

    expect($body->content)->toBe([]);
});
