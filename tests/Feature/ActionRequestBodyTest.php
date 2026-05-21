<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes as OpenApi;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\ActionFixture;
use Radiergummi\OpenApi\Tests\Fixtures\ActionFixtureData;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

// ---------------------------------------------------------------------------
// Fixture controllers — one per item so routes don't bleed across tests
// ---------------------------------------------------------------------------

/**
 * OAPI-010: controller method typed as an Action whose constructor carries a
 * Data class. The generator must walk the Action constructor.
 */
class ActionPatternController extends Controller
{
    public function store(ActionFixture $action): JsonResponse
    {
        return new JsonResponse();
    }
}

/**
 * OAPI-011: controller using #[OpenApi\Response] with an inline schema array.
 */
class InlineSchemaController extends Controller
{
    #[\Radiergummi\OpenApi\Core\Attributes\Response(
        status: 200,
        description: 'Search result',
        schema: ['type' => 'object', 'properties' => ['uuid' => ['type' => 'string'], 'status' => ['type' => 'string']]],
    )]
    public function show(): JsonResponse
    {
        return new JsonResponse();
    }
}

/**
 * OAPI-011 (backwards compat): #[OpenApi\Response] with ref still works.
 */
class InlineSchemaRefController extends Controller
{
    #[\Radiergummi\OpenApi\Core\Attributes\Response(status: 404, description: 'Not found', ref: ActionFixtureData::class)]
    public function show(): JsonResponse
    {
        return new JsonResponse();
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('OAPI-010: extracts request body from Action constructor Data parameter', function (): void {
    config()->set('openapi.request_payload_indirection', [Action::class]);

    \Illuminate\Support\Facades\Route::post(
        '/oa-p1b2/action-pattern',
        [ActionPatternController::class, 'store'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    // The schema for ActionFixtureData must be registered as a component.
    expect($spec['components']['schemas'])->toHaveKey('ActionFixtureData');

    // The POST operation must reference it as the request body.
    $requestBody = $spec['paths']['/oa-p1b2/action-pattern']['post']['requestBody'] ?? null;

    expect($requestBody)->not->toBeNull()
        ->and($requestBody['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ActionFixtureData');
});

it('OAPI-011: inline schema array on Response attribute produces inline 200 schema', function (): void {
    \Illuminate\Support\Facades\Route::get(
        '/oa-p1b2/inline-schema',
        [InlineSchemaController::class, 'show'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $response = $spec['paths']['/oa-p1b2/inline-schema']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('Search result')
        ->and($response['content']['application/json']['schema']['type'])->toBe('object')
        ->and($response['content']['application/json']['schema']['properties']['uuid']['type'])->toBe('string')
        ->and($response['content']['application/json']['schema']['properties']['status']['type'])->toBe('string');
});

it('OAPI-011: inline schema wins over ref when both provided', function (): void {
    // Verified by the attribute constructor: schema takes precedence in buildResponseFromAttribute().
    // This test checks the ref-only path still works (backwards compat).
    \Illuminate\Support\Facades\Route::get(
        '/oa-p1b2/inline-schema-ref',
        [InlineSchemaRefController::class, 'show'],
    );

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $response = $spec['paths']['/oa-p1b2/inline-schema-ref']['get']['responses']['404'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('Not found')
        ->and($response['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ActionFixtureData');
});
