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
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi');

it('runs a stage contributed by a configured plugin', function (): void {
    Route::get('/ping', fn() => 'pong');

    config()->set('openapi.plugins', array_merge(
        (array) config('openapi.plugins', []),
        [MarkerStagePlugin::class],
    ));

    $document = app(OpenApiGenerator::class)->generate(
        app(SpecRegistry::class)->default(),
        'testing',
    );

    expect($document->x)->toBeArray()
        ->and($document->x['plugin-touched'] ?? null)->toBe('yes');
});

class MarkerStagePlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addStage(MarkerStage::class);
    }
}

class MarkerStage implements SpecStage
{
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        $document->x = ['plugin-touched' => 'yes'];
    }
}
