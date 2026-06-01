<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Generator\RouteIndex;
use Radiergummi\OpenApi\Support\Generator\Stages\OverridesStage;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi');

/**
 * Builds a one-operation document: GET /api/users with a named route.
 */
function overridesStageDoc(): OA\OpenApi
{
    $operation = new OA\Get([
        'path' => '/api/users',
        'tags' => ['Users'],
    ]);

    $pathItem = new OA\PathItem(['path' => '/api/users']);
    $pathItem->get = $operation;

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [$pathItem];

    return $doc;
}

function overridesStageCtx(): GenerationContext
{
    return new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
}

it('assigns allowlisted scalar fields onto the matching operation', function (): void {
    $index = new RouteIndex();
    $index->record('api/users', 'GET', 'users.index');

    $matcher = new OverrideMatcher([
        'users.index' => [
            'operationId' => 'listUsers',
            'summary'     => 'List users',
            'deprecated'  => true,
            'tags'        => ['Identity'],
        ],
    ]);

    $doc = overridesStageDoc();
    new OverridesStage($index, $matcher)->apply($doc, overridesStageCtx());

    $op = $doc->paths[0]->get;
    expect($op->operationId)->toBe('listUsers')
        ->and($op->summary)->toBe('List users')
        ->and($op->deprecated)->toBeTrue()
        ->and($op->tags)->toBe(['Identity']);
});

it('maps x-* keys onto the operation x array with the prefix stripped', function (): void {
    $index = new RouteIndex();
    $index->record('api/users', 'GET', 'users.index');

    $matcher = new OverrideMatcher([
        'users.index' => ['x-internal' => true, 'x-rate-limit' => ['max' => 100]],
    ]);

    $doc = overridesStageDoc();
    new OverridesStage($index, $matcher)->apply($doc, overridesStageCtx());

    $op = $doc->paths[0]->get;
    expect($op->x)->toBe(['internal' => true, 'rate-limit' => ['max' => 100]]);
});

it('matches an operation with no route name by uri glob', function (): void {
    $index = new RouteIndex();
    $index->record('api/users', 'GET', null);

    $matcher = new OverrideMatcher([
        'api/*' => ['deprecated' => true],
    ]);

    $doc = overridesStageDoc();
    new OverridesStage($index, $matcher)->apply($doc, overridesStageCtx());

    expect($doc->paths[0]->get->deprecated)->toBeTrue();
});

it('leaves operations untouched when nothing matches', function (): void {
    $index = new RouteIndex();
    $index->record('api/users', 'GET', 'users.index');

    $matcher = new OverrideMatcher([
        'posts.index' => ['deprecated' => true],
    ]);

    $doc = overridesStageDoc();
    new OverridesStage($index, $matcher)->apply($doc, overridesStageCtx());

    $op = $doc->paths[0]->get;
    expect($op->operationId)->toBe(Generator::UNDEFINED)
        ->and($op->deprecated)->toBe(Generator::UNDEFINED);
});
