<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Generator\Stages\OverridesStage;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi');

/**
 * Builds a one-operation document (GET /api/users) and a context that binds the operation to a
 * route descriptor named `$routeName` — mirroring how PathsStage binds actions at generation time.
 *
 * @return array{0: OA\OpenApi, 1: GenerationContext}
 */
function overridesStageFixture(?string $routeName): array
{
    $operation = new OA\Get([
        'path' => '/api/users',
        'tags' => ['Users'],
    ]);

    $pathItem = new OA\PathItem(['path' => '/api/users']);
    $pathItem->get = $operation;

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [$pathItem];

    $route = new Route(['GET'], '/api/users', static fn() => null);

    if ($routeName !== null) {
        $route->name($routeName);
    }

    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
    $ctx->bindAction($operation, new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    ));

    return [$doc, $ctx];
}

it('assigns allowlisted scalar fields onto the matching operation', function (): void {
    $matcher = new OverrideMatcher([
        'users.index' => [
            'operationId' => 'listUsers',
            'summary'     => 'List users',
            'deprecated'  => true,
            'tags'        => ['Identity'],
        ],
    ]);

    [$doc, $ctx] = overridesStageFixture('users.index');
    new OverridesStage($matcher)->apply($doc, $ctx);

    $op = $doc->paths[0]->get;
    expect($op->operationId)->toBe('listUsers')
        ->and($op->summary)->toBe('List users')
        ->and($op->deprecated)->toBeTrue()
        ->and($op->tags)->toBe(['Identity']);
});

it('maps x-* keys onto the operation x array with the prefix stripped', function (): void {
    $matcher = new OverrideMatcher([
        'users.index' => ['x-internal' => true, 'x-rate-limit' => ['max' => 100]],
    ]);

    [$doc, $ctx] = overridesStageFixture('users.index');
    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->paths[0]->get->x)->toBe(['internal' => true, 'rate-limit' => ['max' => 100]]);
});

it('matches an operation with no route name by uri glob', function (): void {
    $matcher = new OverrideMatcher([
        'api/*' => ['deprecated' => true],
    ]);

    [$doc, $ctx] = overridesStageFixture(null);
    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->paths[0]->get->deprecated)->toBeTrue();
});

it('leaves operations untouched when nothing matches', function (): void {
    $matcher = new OverrideMatcher([
        'posts.index' => ['deprecated' => true],
    ]);

    [$doc, $ctx] = overridesStageFixture('users.index');
    new OverridesStage($matcher)->apply($doc, $ctx);

    $op = $doc->paths[0]->get;
    expect($op->operationId)->toBe(Generator::UNDEFINED)
        ->and($op->deprecated)->toBe(Generator::UNDEFINED);
});

it('normalises a multi-tag override to a sequential list', function (): void {
    // Config supplies multiple tags. The result must be a sequential list<string> — the
    // only form that satisfies swagger-php's [string] type validation on both 5.8 and 6.x.
    $matcher = new OverrideMatcher([
        'users.index' => ['tags' => ['Users', 'Admin']],
    ]);

    [$doc, $ctx] = overridesStageFixture('users.index');
    new OverridesStage($matcher)->apply($doc, $ctx);

    $tags = $doc->paths[0]->get->tags;
    expect($tags)->toBe(['Users', 'Admin'])
        ->and(array_is_list($tags))->toBeTrue();
});

it('re-indexes a gappy-keyed tags override to a sequential list', function (): void {
    // Hand-edited config can leave non-sequential integer keys behind. The result must
    // still be a sequential list<string>; without array_values() the gappy keys would
    // survive and array_is_list() would report false.
    $matcher = new OverrideMatcher([
        'users.index' => ['tags' => [1 => 'Users', 3 => 'Admin']],
    ]);

    [$doc, $ctx] = overridesStageFixture('users.index');
    new OverridesStage($matcher)->apply($doc, $ctx);

    $tags = $doc->paths[0]->get->tags;
    expect($tags)->toBe(['Users', 'Admin'])
        ->and(array_is_list($tags))->toBeTrue();
});

it('re-indexes an associative-keyed tags override to a sequential list', function (): void {
    // Associative inner arrays are a realistic hand-edited config shape. The result must
    // be a sequential list<string>, not a string-keyed map.
    $matcher = new OverrideMatcher([
        'users.index' => ['tags' => ['a' => 'Users', 'b' => 'Admin']],
    ]);

    [$doc, $ctx] = overridesStageFixture('users.index');
    new OverridesStage($matcher)->apply($doc, $ctx);

    $tags = $doc->paths[0]->get->tags;
    expect($tags)->toBe(['Users', 'Admin'])
        ->and(array_is_list($tags))->toBeTrue();
});

it('skips a path item with no path even when overrides are configured', function (): void {
    $matcher = new OverrideMatcher(['api/*' => ['deprecated' => true]]);

    $pathItem = new OA\PathItem([]); // path stays Generator::UNDEFINED
    $pathItem->get = new OA\Get([]);

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [$pathItem];

    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->paths[0]->get->deprecated)->toBe(Generator::UNDEFINED);
});
