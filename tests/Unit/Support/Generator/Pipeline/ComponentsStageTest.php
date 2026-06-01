<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\Stages\ComponentsStage;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi');

it('writes registered schemas and responses to components', function (): void {
    $registry = app(ComponentSchemaRegistry::class);
    $registry->registerNamed('Widget', new OA\Schema(['schema' => 'Widget', 'type' => 'object']));
    $registry->registerNamedResponse('NotFound', new OA\Response(['response' => 'NotFound', 'description' => 'Not found']));

    $doc = new OA\OpenApi([]);
    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');

    (new ComponentsStage($registry))->apply($doc, $ctx);

    expect($doc->components)->toBeInstanceOf(OA\Components::class)
        ->and($doc->components->schemas)->toHaveCount(1)
        ->and($doc->components->responses)->toHaveCount(1);
});

it('does not create a components block if there is nothing to write', function (): void {
    $registry = app(ComponentSchemaRegistry::class);

    $doc = new OA\OpenApi([]);
    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');

    (new ComponentsStage($registry))->apply($doc, $ctx);

    expect($doc->components)->not->toBeInstanceOf(OA\Components::class);
});
