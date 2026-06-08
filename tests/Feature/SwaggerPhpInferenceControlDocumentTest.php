<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\InferenceControlDocument;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;

uses()->group('openapi');

// region Helpers

function controlRoutes(): void
{
    Route::get('/servers/{id}', [ServerController::class, 'show']);
}

function enableHarvesterWithFixtures(): void
{
    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhp'],
            $app->make(LoggerInterface::class),
        ),
    );
}

/**
 * @return list<string>
 */
function schemaNames(OA\OpenApi $document): array
{
    if (!$document->components instanceof OA\Components || !is_array($document->components->schemas)) {
        return [];
    }

    return array_map(static fn(OA\Schema $s): string => $s->schema, $document->components->schemas);
}

// endregion

it('excludes harvester-only schemas from the control document', function (): void {
    controlRoutes();
    enableHarvesterWithFixtures();

    // The normal (harvested) generation attaches the authored `Server` schema...
    $harvested = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
    expect(schemaNames($harvested))->toContain('Server');

    // ...but the inference-only control document, built with the harvest stage excluded, must not.
    $control = app(InferenceControlDocument::class)->forSpec('default', 'testing');
    expect(schemaNames($control))->not->toContain('Server');
});

it('is unaffected by a prior in-scope harvested generation (scope isolation)', function (): void {
    controlRoutes();
    enableHarvesterWithFixtures();

    // Pollute the scoped ComponentSchemaRegistry with the harvested run first.
    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    // The control must still be harvest-free — its fresh scoped state must not inherit `Server`.
    $control = app(InferenceControlDocument::class)->forSpec('default', 'testing');
    expect(schemaNames($control))->not->toContain('Server');
});

it('memoizes the control document per spec', function (): void {
    controlRoutes();
    enableHarvesterWithFixtures();

    $service = app(InferenceControlDocument::class);

    expect($service->forSpec('default', 'testing'))
        ->toBe($service->forSpec('default', 'testing'));
});
