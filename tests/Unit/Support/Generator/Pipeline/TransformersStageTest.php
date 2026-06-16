<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\Stages\TransformersStage;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

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

    expect($doc->x)
        ->toBeArray()
        ->and($doc->x['touched'] ?? null)->toBeTrue();
});
