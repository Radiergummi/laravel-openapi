<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Extractors\RequestBodyExtractor;
use Radiergummi\OpenApi\Core\Extractors\SecurityExtractor;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Extractors\UriParametersExtractor;
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Core\Generator\OperationBuilder;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Tests\Unit\Core\Generator\Fixtures\SmokeController;

uses()->group('openapi');

function makeOperationBuilder(array $primaryResolvers = []): OperationBuilder
{
    return new OperationBuilder(
        uriResolver: app(UriParameterResolver::class),
        uriExtractor: app(UriParametersExtractor::class),
        bodyExtractor: app(RequestBodyExtractor::class),
        securityExtractor: app(SecurityExtractor::class),
        standardResponsesExtractor: app(StandardResponsesExtractor::class),
        fileLoader: app(ExampleFileLoader::class),
        primaryResponseResolvers: $primaryResolvers,
    );
}

it('builds an operation for a closure route with no controller', function (): void {
    $closure = static fn(): array => [];
    $route = new Route(['GET'], '/x', $closure);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
        closure: new ReflectionFunction($closure),
    );

    $operation = makeOperationBuilder()->build($descriptor, ['General']);

    expect($operation->tags)->toBe(['General'])
        ->and($operation->responses)->not->toBeEmpty()
        ->and((string) $operation->responses[0]->response)->toBe('200');
});

it('handles a controller method with no attributes (smoke path)', function (): void {
    $route = new Route(['GET'], '/things', [SmokeController::class, 'plain']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(SmokeController::class),
        method: new ReflectionMethod(SmokeController::class, 'plain'),
        summary: null,
        description: null,
    );

    $operation = makeOperationBuilder()->build($descriptor, ['Things']);

    expect($operation->summary)->toBeNull()
        ->and($operation->description)->toBeNull()
        ->and($operation->tags)->toBe(['Things'])
        ->and($operation->deprecated)->toBeFalse()
        // The default fallback primary response is `200 OK` when no resolver claims the action.
        ->and((string) $operation->responses[0]->response)->toBe('200');
});

it('treats a 2xx #[Response] attribute as the primary response, overriding the resolver default', function (): void {
    // Resolver would otherwise emit a 200; the #[Response(201)] attribute must win.
    $stubResolver = new class () implements PrimaryResponseResolver {
        public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
        {
            return new OA\Response([
                'response' => '200',
                'description' => 'from resolver',
            ]);
        }
    };

    $descriptor = new ActionDescriptor(
        route: new Route(['POST'], '/things', [SmokeController::class, 'withResponse']),
        controller: new ReflectionClass(SmokeController::class),
        method: new ReflectionMethod(SmokeController::class, 'withResponse'),
        summary: null,
        description: null,
    );

    $operation = makeOperationBuilder([$stubResolver])->build($descriptor, ['Things']);

    // The first response must be the attribute-derived 201, not the resolver's 200.
    $primary = $operation->responses[0];

    expect((string) $primary->response)->toBe('201')
        ->and($primary->description)->toBe('Created via attribute');

    $statuses = array_map(
        static fn(OA\Response $r): string => (string) $r->response,
        $operation->responses,
    );

    expect($statuses)->not->toContain('200');
});

it('picks up docblock summary and description from the action descriptor', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/things', [SmokeController::class, 'documented']),
        controller: new ReflectionClass(SmokeController::class),
        method: new ReflectionMethod(SmokeController::class, 'documented'),
        summary: 'Documented action.',
        description: 'Longer body.',
    );

    $operation = makeOperationBuilder()->build($descriptor, ['Things']);

    expect($operation->summary)->toBe('Documented action.')
        ->and($operation->description)->toBe('Longer body.');
});
