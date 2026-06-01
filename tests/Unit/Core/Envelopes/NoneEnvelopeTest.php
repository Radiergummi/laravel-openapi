<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\NoneEnvelope;

uses()->group('openapi');

it('returns a bodyless ErrorResponse for every descriptor', function (): void {
    $envelope = new NoneEnvelope();

    $cases = [
        new ErrorDescriptor(status: 401, exceptionClass: AuthenticationException::class, description: 'Unauthenticated'),
        new ErrorDescriptor(status: 422, exceptionClass: ValidationException::class, description: 'Validation failed'),
        new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error'),
    ];

    foreach ($cases as $descriptor) {
        $response = $envelope->resolveErrorResponse($descriptor);

        expect($response)->toBeInstanceOf(ErrorResponse::class);
        expect($response->content)->toBe([]);
        expect($response->headers)->toBe([]);
        expect($response->links)->toBe([]);
        expect($response->description)->toBeNull();
    }
});
