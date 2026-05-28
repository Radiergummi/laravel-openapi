<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

function spec(): SpecDefinition
{
    return new SpecDefinition(
        name: 'default',
        info: new OA\Info(['title' => 'Test', 'version' => '0.0.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: 'openapi.yaml',
        routeUri: null,
        playgroundUri: null,
    );
}

it('returns null when no action is bound for an operation', function (): void {
    $ctx = new GenerationContext(spec(), 'testing');

    expect($ctx->actionFor(new OA\Get([])))->toBeNull();
});

it('looks up the bound action descriptor by operation identity', function (): void {
    $ctx = new GenerationContext(spec(), 'testing');
    $op = new OA\Get([]);
    $action = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');

    $ctx->bindAction($op, $action);

    expect($ctx->actionFor($op))->toBe($action);
});

it('distinguishes operations by object identity, not by data', function (): void {
    $ctx = new GenerationContext(spec(), 'testing');
    $opA = new OA\Get(['operationId' => 'shared']);
    $opB = new OA\Get(['operationId' => 'shared']);
    $action = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');

    $ctx->bindAction($opA, $action);

    expect($ctx->actionFor($opA))->toBe($action)
        ->and($ctx->actionFor($opB))->toBeNull();
});
