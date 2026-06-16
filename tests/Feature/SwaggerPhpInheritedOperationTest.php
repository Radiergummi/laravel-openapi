<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance\SalesReportController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance\WidgetController;

uses()->group('openapi');

// region Helpers

function inheritanceHarvest(): OA\OpenApi
{
    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhpInheritance'],
            $app->make(LoggerInterface::class),
        ),
    );

    return app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
}

function inheritanceOperation(OA\OpenApi $document, string $path): ?OA\Operation
{
    foreach (is_array($document->paths) ? $document->paths : [] as $pathItem) {
        if ((string) $pathItem->path === $path && $pathItem->get instanceof OA\Operation) {
            return $pathItem->get;
        }
    }

    return null;
}

function inheritancePrimaryRef(OA\Operation $operation): ?string
{
    foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
        if ((string) $response->response !== '200' || !is_array($response->content)) {
            continue;
        }

        foreach ($response->content as $mediaType) {
            $ref = $mediaType->schema instanceof OA\Schema ? $mediaType->schema->ref : null;

            if (is_string($ref)) {
                return $ref;
            }
        }
    }

    return null;
}

// endregion

it('merges an @OA operation authored on a parent class onto the inheriting route', function (): void {
    Route::get('/reports/sales', [SalesReportController::class, 'index']);

    $operation = inheritanceOperation(inheritanceHarvest(), '/reports/sales');

    expect($operation)->not
        ->toBeNull()
        ->and($operation->summary)->toBe('Authored on the parent controller')
        ->and(inheritancePrimaryRef($operation))->toBe('#/components/schemas/SalesReport');
});

it('merges an @OA operation authored in a trait onto a route using that trait', function (): void {
    Route::get('/widgets', [WidgetController::class, 'index']);

    $operation = inheritanceOperation(inheritanceHarvest(), '/widgets');

    expect($operation)->not
        ->toBeNull()
        ->and($operation->summary)->toBe('Authored in a trait')
        ->and(inheritancePrimaryRef($operation))->toBe('#/components/schemas/Widget');
});
