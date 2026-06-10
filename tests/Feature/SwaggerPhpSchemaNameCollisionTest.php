<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpCollision\AuthoredInvoiceController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpCollision\ConventionInvoiceController;

uses()->group('openapi');

it('reports a finding and keeps the convention component when an authored schema shares its name', function (): void {
    Route::get('/conv/invoices/{id}', [ConventionInvoiceController::class, 'show']);
    Route::get('/auth/invoices/{id}', [AuthoredInvoiceController::class, 'show']);

    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhpCollision'],
            $app->make(LoggerInterface::class),
        ),
    );

    $collector = new ArrayFindingsCollector();
    app()->instance(FindingsCollector::class, $collector);

    $document = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    // The collision is surfaced as a finding naming the colliding schema.
    $collisions = array_values(array_filter(
        $collector->all(),
        static fn($finding): bool => $finding->ruleId === 'component.schema-name-collision',
    ));
    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]->context['schema'])->toBe('Invoice');

    // First-wins: the document's `Invoice` component is the convention shape, not the authored one.
    $invoice = null;

    foreach (is_array($document->components->schemas) ? $document->components->schemas : [] as $schema) {
        if ($schema instanceof OA\Schema && $schema->schema === 'Invoice') {
            $invoice = $schema;
        }
    }

    $propertyNames = array_map(
        static fn(OA\Property $p): string => (string) $p->property,
        is_array($invoice?->properties) ? $invoice->properties : [],
    );

    expect($invoice)->not->toBeNull()
        ->and($propertyNames)->toContain('id')
        ->and($propertyNames)->toContain('amount_cents')
        ->and($propertyNames)->not->toContain('number');
});
