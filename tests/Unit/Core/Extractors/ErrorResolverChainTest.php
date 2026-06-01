<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\LaravelEnvelope;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

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

it('exposes the route action descriptor for per-route resolver scoping', function (): void {
    $action = new ActionDescriptor(
        route: new Route(['GET'], '/widgets/{widget}', fn() => null)->middleware(['api']),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    $jsonApiOnly = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
        {
            if ($descriptor->action === null || !in_array('api', $descriptor->action->route->middleware(), true)) {
                return null;
            }

            return new ErrorResponse(content: [
                new OA\MediaType(['mediaType' => 'application/vnd.api+json']),
            ]);
        }
    };

    $apiResponse = $jsonApiOnly->resolveErrorResponse(new ErrorDescriptor(
        status: 404,
        exceptionClass: null,
        description: 'Not Found',
        action: $action,
    ));

    expect($apiResponse)->not->toBeNull();
    expect($apiResponse->content[0]->mediaType)->toBe('application/vnd.api+json');

    $defaultResponse = $jsonApiOnly->resolveErrorResponse(new ErrorDescriptor(
        status: 404,
        exceptionClass: null,
        description: 'Not Found',
        action: null,
    ));

    expect($defaultResponse)->toBeNull();
});
