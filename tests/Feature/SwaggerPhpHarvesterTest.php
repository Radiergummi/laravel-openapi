<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\InvoiceController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;

uses()->group('openapi');

// region Helpers

function harvesterRoutes(): void
{
    Route::get('/servers/{id}', [ServerController::class, 'show']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
}

function pointScannerAtFixtures(): void
{
    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhp'],
            $app->make(LoggerInterface::class),
        ),
    );
}

function generateDocument(): OA\OpenApi
{
    return app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
}

/**
 * @return list<string>
 */
function documentSchemaNames(OA\OpenApi $document): array
{
    if (!$document->components instanceof OA\Components || !is_array($document->components->schemas)) {
        return [];
    }

    return array_map(static fn(OA\Schema $s): string => $s->schema, $document->components->schemas);
}

function primaryRefForPath(OA\OpenApi $document, string $needle): ?string
{
    foreach (is_array($document->paths) ? $document->paths : [] as $pathItem) {
        if (!str_contains((string) $pathItem->path, $needle) || !$pathItem->get instanceof OA\Operation) {
            continue;
        }

        foreach (is_array($pathItem->get->responses) ? $pathItem->get->responses : [] as $response) {
            if ((string) $response->response !== '200' || !is_array($response->content)) {
                continue;
            }

            foreach ($response->content as $mediaType) {
                $ref = $mediaType->schema instanceof OA\Schema ? $mediaType->schema->ref : null;

                if (is_string($ref) && str_starts_with($ref, '#/')) {
                    return $ref;
                }
            }
        }
    }

    return null;
}

// endregion

it('harvests authored schemas and response refs into the generated document', function (): void {
    harvesterRoutes();
    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);
    pointScannerAtFixtures();

    $document = generateDocument();

    // Attribute model (Coolify shape): typed return → $ref body.
    expect(primaryRefForPath($document, 'servers'))->toBe('#/components/schemas/Server');

    // Docblock operation (Invoice-Ninja shape): authored @OA\Response ref merged in.
    expect(primaryRefForPath($document, 'invoices'))->toBe('#/components/schemas/Invoice');

    // Both authored schemas land in components, transitively (Invoice → InvoiceLine).
    expect(documentSchemaNames($document))
        ->toContain('Server')
        ->toContain('Invoice')
        ->toContain('InvoiceLine');
});

it('produces a document that serializes and validates as OpenAPI 3.1', function (): void {
    harvesterRoutes();
    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);
    pointScannerAtFixtures();

    $document = generateDocument();

    // Reused swagger-php objects serialise cleanly through our document.
    expect($document->toYaml())->toContain('#/components/schemas/Server');

    $analysis = new Analysis([], new Context());
    $analysis->openapi = $document;

    expect($analysis->validate())->toBeTrue();
});

it('is inert when the plugin is not registered', function (): void {
    harvesterRoutes();
    pointScannerAtFixtures();
    // Deliberately NOT adding SwaggerPhpPlugin to config('openapi.plugins').

    $document = generateDocument();

    expect(primaryRefForPath($document, 'servers'))->toBeNull()
        ->and(documentSchemaNames($document))
        ->not->toContain('Server')
        ->not->toContain('Invoice');
});
