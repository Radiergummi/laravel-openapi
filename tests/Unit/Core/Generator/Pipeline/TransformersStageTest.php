<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Generator\Pipeline\GenerationContext;
use Radiergummi\OpenApi\Core\Generator\Pipeline\TransformersStage;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

uses()->group('openapi');

afterEach(function (): void {
    OpenApiExtensions::flush();
});

it('invokes registered document transformers on the assembled document', function (): void {
    OpenApiExtensions::transformDocument(static function (OA\OpenApi $doc): void {
        $doc->x = ['touched' => true];
    });

    $doc = new OA\OpenApi([]);
    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');

    (new TransformersStage())->apply($doc, $ctx);

    expect($doc->x)->toBeArray()
        ->and($doc->x['touched'] ?? null)->toBeTrue();
});
