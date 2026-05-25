<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Errors\JsonApiEnvelope;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;

uses()->group('openapi');

it('refs ErrorDocument with application/vnd.api+json for every status', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new JsonApiEnvelope($registry);

    $cases = [
        new ErrorDescriptor(401, AuthenticationException::class, 'Unauthenticated'),
        new ErrorDescriptor(422, ValidationException::class, 'Validation failed'),
        new ErrorDescriptor(500, null, 'Server error'),
    ];

    foreach ($cases as $descriptor) {
        $response = $envelope->resolveErrorResponse($descriptor);
        $media = $response->content[0];
        expect($media->mediaType)->toBe('application/vnd.api+json');
        expect($media->schema->ref)->toBe($registry->qualifyKey('ErrorDocument'));
    }
});

it('registers the ErrorDocument schema', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new JsonApiEnvelope($registry);

    $envelope->resolveErrorResponse(new ErrorDescriptor(500, null, 'Server error'));

    expect($registry->hasKey('ErrorDocument'))->toBeTrue();
});
