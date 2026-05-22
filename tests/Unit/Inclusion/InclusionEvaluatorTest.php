<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Inclusion;

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Inclusion\TraceEntry;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;
use ReflectionClass;
use ReflectionMethod;

// Fixture: class carrying #[Spec('v1')] used by the spec-attribute tests below.
#[\Radiergummi\OpenApi\Core\Attributes\Spec('v1')]
final class FxSpecV1Class
{
    public function handle(): void {}
}

it('TraceEntry holds stage, name, passed, reason', function (): void {
    $entry = new TraceEntry('global-filter', 'SkipNovaRoutes', true, 'not a Nova route');

    expect($entry->stage)->toBe('global-filter')
        ->and($entry->name)->toBe('SkipNovaRoutes')
        ->and($entry->passed)->toBeTrue()
        ->and($entry->reason)->toBe('not a Nova route');
});

it('InclusionDecision holds included, trace, summary', function (): void {
    $decision = new InclusionDecision(true, [], 'matches default spec');

    expect($decision->included)->toBeTrue()
        ->and($decision->trace)->toBe([])
        ->and($decision->summary)->toBe('matches default spec');
});

/**
 * Builds a minimal ActionDescriptor for testing.
 *
 * @param null|ReflectionClass<object> $controller
 */
function makeDescriptor(
    string $uri,
    array $middleware = [],
    ?ReflectionClass $controller = null,
    ?ReflectionMethod $action = null,
): ActionDescriptor {
    $route = (new Route(['GET'], $uri, fn() => null))->middleware($middleware);

    return new ActionDescriptor(
        route: $route,
        controller: $controller,
        method: $action,
        summary: null,
        description: null,
        throws: [],
        closure: null,
    );
}

function makeSpec(string $name, array $match = []): SpecDefinition
{
    return new SpecDefinition(
        name: $name,
        info: new OA\Info(['title' => $name, 'version' => '1.0']),
        servers: [],
        tags: [],
        match: $match,
        outputPath: "/tmp/{$name}.yaml",
        routeUri: null,
        playgroundUri: null,
    );
}

function makeEvaluator(array $globalFilters = []): InclusionEvaluator
{
    return new InclusionEvaluator(
        globalFilters: $globalFilters,
        matcher: new SpecMatcher(),
        specResolver: new SpecResolver(),
        visibility: new VisibilityResolver(VisibilityMode::Public),
    );
}

it('includes a route in default spec when nothing opposes', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('default');

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes a route when any global RouteFilter returns shouldSkip=true', function (): void {
    $filter = new class () implements RouteFilter {
        public function shouldSkip(Route $route): bool
        {
            return true;
        }
    };

    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator([$filter])->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeFalse()
        ->and($decision->summary)->toContain('global filter');
});

it('includes a route in a named spec when the spec match config matches', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes a route from a named spec when match config does not match', function (): void {
    $descriptor = makeDescriptor('api/v2/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeFalse()
        ->and($decision->summary)->toContain('match');
});

it('includes a route with #[Spec(v1)] in spec v1 regardless of match config', function (): void {
    // FxSpecV1Class carries #[Spec('v1')] at the class level.
    $class = new ReflectionClass(FxSpecV1Class::class);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/legacy/x', controller: $class, action: $method);
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);  // would NOT match by prefix

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes a route with #[Spec(v1)] from spec v2 even if v2 match matches', function (): void {
    $class = new ReflectionClass(FxSpecV1Class::class);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/v2/foo', controller: $class, action: $method);
    $spec = makeSpec('v2', ['prefix' => 'api/v2/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeFalse();
});

it('excludes a route when #[Hide] applies in the current environment', function (): void {
    $hidden = new class () {
        #[Hide] public function handle(): void {}
    };
    $class = new ReflectionClass($hidden);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/v1/x', controller: $class, action: $method);
    $spec = makeSpec('default');

    $decision = makeEvaluator()->decide($descriptor, $spec, 'production');

    expect($decision->included)->toBeFalse()
        ->and($decision->summary)->toContain('hidden');
});

it('produces a trace with one entry per check', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    $stages = array_column(array_map(fn($t) => (array) $t, $decision->trace), 'stage');
    expect($stages)->toContain('spec-match')
        ->and($stages)->toContain('visibility');
});
