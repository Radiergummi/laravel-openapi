<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Lint\SuppressionScope;
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
