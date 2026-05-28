<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi');

it('does not resolve any OpenAPI generation service when handling an unrelated request', function (): void {
    Route::get('/ping', fn() => 'pong');

    // Sanity: make a request to a non-OpenAPI route.
    $this->get('/ping')->assertOk();

    // Assert none of these scoped bindings were resolved.
    $tracked = [
        OpenApiGenerator::class,
        OpenApiGenerationOrchestrator::class,
        InclusionEvaluator::class,
        SpecRegistry::class,
    ];

    foreach ($tracked as $cls) {
        expect($this->app->resolved($cls))
            ->toBeFalse("expected {$cls} NOT to be resolved on an unrelated request");
    }
});
