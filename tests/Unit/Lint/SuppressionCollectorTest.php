<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Lint\SuppressionScope;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint\IgnoreLintFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint\SnakeCasedFormRequest;

uses()->group('openapi', 'lint');

it('collects a class-level IgnoreLint directive from a FormRequest method parameter', function (): void {
    /** @var class-string $controllerClass */
    $controllerClass = IgnoreLintFixtureController::class;
    $controller = new ReflectionClass($controllerClass);
    $method = $controller->getMethod('viaFormRequest');

    $descriptor = new ActionDescriptor(
        route: new Route(['POST'], 'fixture/form-request', []),
        controller: $controller,
        method: $method,
        summary: null,
        description: null,
    );

    $directives = app(SuppressionCollector::class)->collect([$descriptor]);

    $classDirectives = array_values(array_filter(
        $directives,
        static fn($d) => $d->scope === SuppressionScope::ClassScope
            && $d->targetClass === SnakeCasedFormRequest::class,
    ));

    expect($classDirectives)->toHaveCount(1)
        ->and($classDirectives[0]->ruleId)->toBe('field.name-naming-inconsistent');
});

it('registers JsonResource as a payload class when ApiResourcesPlugin is enabled', function (): void {
    $payloadClasses = app(OpenApiRegistry::class)->payloadClasses;

    expect($payloadClasses)->toContain(JsonResource::class);
});

it('collects class-level IgnoreLint from a JsonResource registered in the component schema map', function (): void {
    $registry = app(Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry::class);
    $registry->register(
        Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint\SnakeCasedJsonResource::class,
        new OpenApi\Annotations\Schema(['schema' => 'SnakeCasedJsonResource']),
    );

    $directives = app(SuppressionCollector::class)->collectFromComponentSchemas(
        $registry->componentClassMap(),
    );

    $matching = array_values(array_filter(
        $directives,
        static fn($d) => $d->scope === SuppressionScope::ClassScope
            && $d->targetClass === Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint\SnakeCasedJsonResource::class,
    ));

    expect($matching)->toHaveCount(1)
        ->and($matching[0]->ruleId)->toBe('field.name-naming-inconsistent');
});
