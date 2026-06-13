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

// region Webhook overrides

it('applies a config override keyed by route name to a webhook operation', function (): void {
    $matcher = new OverrideMatcher([
        'webhooks.payment.received' => ['summary' => 'Payment received', 'deprecated' => true],
    ]);

    $operation = new OA\Post([]);
    $webhook = new OA\Webhook(['webhook' => 'payment.received']);
    $webhook->post = $operation;

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [];
    $doc->webhooks = [$webhook];

    $route = new Route(['POST'], '/webhooks/payment', static fn() => null);
    $route->name('webhooks.payment.received');

    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
    $ctx->bindAction($operation, new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    ));

    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->webhooks[0]->post->summary)->toBe('Payment received')
        ->and($doc->webhooks[0]->post->deprecated)->toBeTrue();
});

it('applies a config override keyed by webhook name via glob to a webhook operation', function (): void {
    $matcher = new OverrideMatcher([
        // Keyed by the webhook name (what appears in the spec), not the route name or URI.
        'payment.*' => ['summary' => 'Payment event', 'deprecated' => true],
    ]);

    $operation = new OA\Post([]);
    $webhook = new OA\Webhook(['webhook' => 'payment.received']);
    $webhook->post = $operation;

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [];
    $doc->webhooks = [$webhook];

    // Route name and URI deliberately do NOT match the override key; only the webhook name does.
    $route = new Route(['POST'], '/webhooks/payment', static fn() => null);
    $route->name('webhooks.payment.received');

    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
    $ctx->bindAction($operation, new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    ));

    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->webhooks[0]->post->summary)->toBe('Payment event')
        ->and($doc->webhooks[0]->post->deprecated)->toBeTrue();
});

it('leaves webhook operations untouched when no override matches', function (): void {
    $matcher = new OverrideMatcher([
        'other.route' => ['deprecated' => true],
    ]);

    $operation = new OA\Post([]);
    $webhook = new OA\Webhook(['webhook' => 'payment.received']);
    $webhook->post = $operation;

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [];
    $doc->webhooks = [$webhook];

    $route = new Route(['POST'], '/webhooks/payment', static fn() => null);
    $route->name('webhooks.payment.received');

    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
    $ctx->bindAction($operation, new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    ));

    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->webhooks[0]->post->deprecated)->toBe(Generator::UNDEFINED);
});

it('does not fail when webhooks is undefined', function (): void {
    $matcher = new OverrideMatcher([
        'users.index' => ['deprecated' => true],
    ]);

    $operation = new OA\Get(['path' => '/api/users']);
    $pathItem = new OA\PathItem(['path' => '/api/users']);
    $pathItem->get = $operation;

    $doc = new OA\OpenApi(['openapi' => '3.1.0']);
    $doc->paths = [$pathItem];
    // $doc->webhooks intentionally left as Generator::UNDEFINED

    $route = new Route(['GET'], '/api/users', static fn() => null);
    $route->name('users.index');

    $ctx = new GenerationContext(app(SpecRegistry::class)->default(), 'testing');
    $ctx->bindAction($operation, new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    ));

    new OverridesStage($matcher)->apply($doc, $ctx);

    expect($doc->paths[0]->get->deprecated)->toBeTrue();
});

// endregion
