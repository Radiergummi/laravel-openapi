<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\Stages\SecurityStage;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi');

it('writes securitySchemes to components from OperationBuilder', function (): void {
    $doc = new OA\OpenApi([]);
    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');

    (new SecurityStage(app(OperationBuilder::class)))->apply($doc, $ctx);

    expect($doc->components)->toBeInstanceOf(OA\Components::class)
        ->and($doc->components->securitySchemes)->toBeArray();
});

it('merges into existing components instead of overwriting', function (): void {
    $doc = new OA\OpenApi([]);
    $doc->components = new OA\Components(['schemas' => ['Foo' => new OA\Schema(['type' => 'object'])]]);
    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');

    (new SecurityStage(app(OperationBuilder::class)))->apply($doc, $ctx);

    expect($doc->components->schemas)->toHaveCount(1)
        ->and($doc->components->securitySchemes)->toBeArray();
});
