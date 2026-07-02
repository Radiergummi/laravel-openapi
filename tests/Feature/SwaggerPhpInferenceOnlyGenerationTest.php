<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\InferenceView;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\InferenceRetention;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\AttributeServer;
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

/**
 * Generates the harvested document with the inferred view retained, returning both the primary
 * document and the {@see InferenceView} assembled from the retained side channel of that single run.
 *
 * @return array{document: OA\OpenApi, view: InferenceView}
 */
function generationWithRetainedView(): array
{
    $document = app(OpenApiGenerationOrchestrator::class)
        ->generateOne('default', 'testing', retainInferredView: true);

    return [
        'document' => $document,
        'view' => InferenceView::fromRetention(
            $document,
            app(ComponentSchemaRegistry::class),
            app(InferenceRetention::class),
        ),
    ];
}

// endregion

it('excludes harvester-only schemas from the retained inference view', function (): void {
    controlRoutes();
    enableHarvesterWithFixtures();

    ['document' => $document, 'view' => $view] = generationWithRetainedView();

    // The harvested document attaches the authored `Server` schema...
    expect(schemaNames($document))->toContain('Server');

    // ...but the retained inference view, sourced from the same single run, must not expose it.
    expect($view->schemaForName('Server'))->toBeNull();
});

it('retains no harvester schema even after a prior in-scope harvested generation (scope isolation)', function (): void {
    controlRoutes();
    enableHarvesterWithFixtures();

    // Pollute the scoped state with a first harvested generation.
    app(OpenApiGenerationOrchestrator::class)->generateOne('default', 'testing');

    // A subsequent retained run gets fresh scoped state, so the view never inherits `Server`.
    expect(generationWithRetainedView()['view']->schemaForName('Server'))->toBeNull();
});

it('omits a class inference produces no schema for from the retained view', function (): void {
    controlRoutes();
    enableHarvesterWithFixtures();

    // AttributeServer is a plain class with only an authored #[OA\Schema]; inference alone yields no
    // component schema for it, so it must have no inferred counterpart in the retained view.
    expect(generationWithRetainedView()['view']->schemaForClass(AttributeServer::class))->toBeNull();
});
