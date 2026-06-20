<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\Rules\OverridesUnused;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\OverridesWebhookController;

uses()->group('openapi', 'lint');

function overridesUnusedDescriptor(string $uri, ?string $name): ActionDescriptor
{
    $route = new Route(['GET'], $uri, static fn() => null);

    if ($name !== null) {
        $route->name($name);
    }

    return new ActionDescriptor(
        route: $route,
        controller: null,
        method: null,
        summary: null,
        description: null,
    );
}

/**
 * A descriptor for the `#[Webhook(name: 'payment.received')]` fixture handler. Its route URI and
 * name deliberately differ from the webhook name so tests can isolate webhook-name matching.
 */
function overridesUnusedWebhookDescriptor(): ActionDescriptor
{
    $route = new Route(['POST'], 'webhooks/payment', static fn() => null);
    $route->name('webhooks.payment.received');

    return new ActionDescriptor(
        route: $route,
        controller: null,
        method: new ReflectionMethod(OverridesWebhookController::class, 'handlePaymentReceived'),
        summary: null,
        description: null,
    );
}

/**
 * @param array<string, array<string, mixed>> $overrides
 * @param list<ActionDescriptor>              $descriptors
 *
 * @return list<Radiergummi\OpenApi\Lint\Finding>
 */
function overridesUnusedCollect(array $overrides, array $descriptors): array
{
    $collector = new ArrayFindingsCollector();

    new OverridesUnused(new OverrideMatcher($overrides))
        ->checkConfiguration(app(SpecRegistry::class), $descriptors, $collector);

    return $collector->all();
}

it('has the correct id and severity', function (): void {
    $rule = new OverridesUnused(new OverrideMatcher([]));

    expect($rule->id)
        ->toBe('overrides.unused')
        ->and($rule->severity)->toBe(Severity::Inconsistent);
});

it('stays silent when every key matches a route name or uri', function (): void {
    $findings = overridesUnusedCollect(
        [
            'users.show' => ['summary' => 'x'],
            'api/posts/*' => ['deprecated' => true],
        ],
        [
            overridesUnusedDescriptor('api/users/{user}', 'users.show'),
            overridesUnusedDescriptor('api/posts/list', 'posts.index'),
        ],
    );

    expect($findings)->toBe([]);
});

it('flags a key that matches no route or uri', function (): void {
    $findings = overridesUnusedCollect(
        ['legacy.foo' => ['summary' => 'x']],
        [overridesUnusedDescriptor('api/users/{user}', 'users.show')],
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('overrides.unused')
        ->and($findings[0]->message)->toContain('legacy.foo');
});

it('does not flag an override keyed by a webhook name the stage applies', function (): void {
    // Exact key and glob key, both against the webhook name — the same way OverridesStage matches.
    $findings = overridesUnusedCollect(
        [
            'payment.received' => ['summary' => 'x'],
            'payment.*' => ['deprecated' => true],
        ],
        [overridesUnusedWebhookDescriptor()],
    );

    expect($findings)->toBe([]);
});

it('flags a key matching neither route, uri, nor webhook name', function (): void {
    // The webhook's route URI (webhooks/payment) must NOT satisfy a key — webhooks match by name.
    $findings = overridesUnusedCollect(
        ['webhooks/payment' => ['summary' => 'x']],
        [overridesUnusedWebhookDescriptor()],
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('overrides.unused')
        ->and($findings[0]->message)->toContain('webhooks/payment');
});
