<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\Stages\PathsStage;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Unit\Support\Generator\Fixtures\SmokeController;

uses()->group('openapi');

it('binds each produced OA\\Operation to its ActionDescriptor in the context', function (): void {
    Route::get('/things', [SmokeController::class, 'plain']);

    $doc = new OA\OpenApi([]);
    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');

    app(PathsStage::class)->apply($doc, $ctx);

    expect($doc->paths)->toBeArray()->not->toBeEmpty();

    $pathItem = collect($doc->paths)->first(
        fn(OA\PathItem $p): bool => $p->path === '/things',
    );
    assert($pathItem instanceof OA\PathItem);

    $operation = $pathItem->get;
    assert($operation instanceof OA\Operation);

    expect($ctx->actionFor($operation))
        ->not->toBeNull()
        ->and($ctx->actionFor($operation)?->route->uri())->toBe('things');
});
