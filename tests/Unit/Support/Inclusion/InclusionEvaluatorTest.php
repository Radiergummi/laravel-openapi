<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Inclusion;

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\Attributes\Spec;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;
use Radiergummi\OpenApi\Events\SkipReason;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Inclusion\TraceEntry;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecMatcher;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;
use Radiergummi\OpenApi\Support\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Support\Visibility\VisibilityResolver;
use ReflectionClass;
use ReflectionMethod;

// Fixture: class carrying #[Spec('v1')] used by the spec-attribute tests below.
#[Spec('v1')]
final class FxSpecV1Class
{
    public function handle(): void {}
}

it('TraceEntry holds stage, name, passed, reason', function (): void {
    $entry = new TraceEntry('global-filter', 'SkipNovaRoutes', true, 'not a Nova route');

    expect($entry->stage)
        ->toBe('global-filter')
        ->and($entry->name)->toBe('SkipNovaRoutes')
        ->and($entry->passed)->toBeTrue()
        ->and($entry->reason)->toBe('not a Nova route');
});

it('InclusionDecision holds included, trace, summary, reason', function (): void {
    $included = new InclusionDecision(true, [], 'matches default spec');

    expect($included->included)
        ->toBeTrue()
        ->and($included->trace)->toBe([])
        ->and($included->summary)->toBe('matches default spec')
        ->and($included->reason)->toBeNull();

    $excluded = new InclusionDecision(
        false,
        [],
        'excluded',
        SkipReason::Visibility,
    );

    expect($excluded->reason)->toBe(SkipReason::Visibility);
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
        middlewareGatherer: app(RouteMiddlewareGatherer::class),
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

    expect($decision->included)
        ->toBeFalse()
        ->and($decision->summary)->toContain('global filter')
        ->and($decision->reason)->toBe(SkipReason::GlobalFilter);
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

    expect($decision->included)
        ->toBeFalse()
        ->and($decision->summary)->toContain('match')
        ->and($decision->reason)->toBe(SkipReason::SpecMembership);
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
        #[Hide]
        public function handle(): void {}
    };
    $class = new ReflectionClass($hidden);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/v1/x', controller: $class, action: $method);
    $spec = makeSpec('default');

    $decision = makeEvaluator()->decide($descriptor, $spec, 'production');

    expect($decision->included)
        ->toBeFalse()
        ->and($decision->summary)->toContain('hidden')
        ->and($decision->reason)->toBe(SkipReason::Visibility);
});

it('includes any route in the default spec when no match config is given (catch-all)', function (): void {
    $descriptor = makeDescriptor('api/anywhere');
    $spec = makeSpec('default'); // empty match

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes every route from a named spec with no match config (matches nothing)', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('partner'); // empty match — named spec misconfiguration

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)
        ->toBeFalse()
        ->and($decision->summary)->toContain("named spec 'partner' has no match config");
});

it('produces a trace with one entry per check', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    $stages = array_column(array_map(fn($t) => (array) $t, $decision->trace), 'stage');
    expect($stages)
        ->toContain('spec-match')
        ->and($stages)->toContain('visibility');
});
