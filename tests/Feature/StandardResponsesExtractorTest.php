<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\ThrowsExtractor;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Tests\Fixtures\FixtureErrorResponseFactory;
use Radiergummi\OpenApi\Tests\Fixtures\StandardResponsesFixtureController;

uses()->group('openapi', 'openapi-standard-responses-extractor');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<string, array{status: int, description: string}> $exceptionMap
 * @param array<string, array{status: int, description: string}> $middlewareMap
 */
function makeExtractor(array $exceptionMap = [], array $middlewareMap = []): StandardResponsesExtractor
{
    $registry = new ComponentSchemaRegistry();

    return new StandardResponsesExtractor(
        registry: $registry,
        findings: new ArrayFindingsCollector(),
        errorResponseFactories: [new FixtureErrorResponseFactory($registry)],
        exceptionMap: $exceptionMap,
        middlewareMap: $middlewareMap,
    );
}

/**
 * Builds an ActionDescriptor for the given method on the fixture controller.
 * The route carries no middleware so middleware-derived responses are absent.
 *
 * @throws ReflectionException
 */
function makeDescriptor(string $method): ActionDescriptor
{
    $reflection = new ReflectionMethod(StandardResponsesFixtureController::class, $method);
    $route      = new Route('GET', '/test', static fn() => null);
    $throws     = ThrowsExtractor::create()->extract($reflection);

    return new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
        throws: $throws,
    );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('uses the #[Throws] attribute on the exception class when present', function (): void {
    $responses = makeExtractor()->extract(makeDescriptor('throwsTeapot'));

    $statuses = array_map(static fn($r) => (int) $r->response, $responses);

    expect($statuses)->toContain(418);

    $teapot = array_values(array_filter($responses, static fn($r) => $r->response === '418'))[0];

    expect($teapot->description)->toBe("I'm a teapot");
});

it('falls through to the exception map when no #[Throws] attribute is present', function (): void {
    $responses = makeExtractor(exceptionMap: [
        'ModelNotFoundException' => ['status' => 404, 'description' => 'Resource not found'],
    ])->extract(makeDescriptor('throwsModelNotFound'));

    $statuses = array_map(static fn($r) => (int) $r->response, $responses);

    expect($statuses)->toContain(404);
});

it('emits no responses when there are no @throws annotations and no middleware', function (): void {
    $responses = makeExtractor()->extract(makeDescriptor('throwsNothing'));

    expect($responses)->toBe([]);
});
