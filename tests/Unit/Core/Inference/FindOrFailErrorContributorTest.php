<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\FindOrFailErrorContributor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\FindOrFailFixtureController;

uses()->group('openapi');

// region Helpers

function findOrFailContributor(): FindOrFailErrorContributor
{
    return new FindOrFailErrorContributor(new MethodBodyScanner(), [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);
}

/**
 * @param class-string $controller
 */
function findOrFailActionDescriptor(string $controller, string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

// endregion

// region Whitelisted call shapes

it('emits a 404 for a static Model::findOrFail()', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'staticFindOrFail'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404)
        ->and($result[0]->description)->toBe('Resource not found')
        ->and($result[0]->exceptionClass)->toBe(ModelNotFoundException::class);
});

it('emits a 404 for a query-builder ->findOrFail()', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'queryFindOrFail'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

it('emits a 404 for a ->firstOrFail()', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'firstOrFail'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

it('finds a findOrFail inside an if guard', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'findOrFailInGuard'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

it('matches the method name case-insensitively', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'mixedCaseFindOrFail'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

it('emits a single 404 even when several failing lookups are present', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'boundAndFindOrFail'),
    );

    // The framework throws the same ModelNotFoundException regardless of which lookup fails.
    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

it('binds the action onto the emitted descriptor', function (): void {
    $descriptor = findOrFailActionDescriptor(FindOrFailFixtureController::class, 'staticFindOrFail');

    $result = findOrFailContributor()->contribute($descriptor);

    expect($result[0]->action)->toBe($descriptor);
});

// endregion

// region Out of scope: not the throwing idioms

it('does not match a non-throwing find() or firstOr()', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'nonThrowingFind'),
    );

    expect($result)->toBe([]);
});

it('stays silent when the body contains no findOrFail at all', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'noLookupAtAll'),
    );

    expect($result)->toBe([]);
});

it('ignores a findOrFail beyond the statement limit', function (): void {
    $result = findOrFailContributor()->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'findOrFailBeyondStatementLimit'),
    );

    expect($result)->toBe([]);
});

it('returns an empty list when the config has no ModelNotFoundException entry', function (): void {
    $contributor = new FindOrFailErrorContributor(new MethodBodyScanner(), []);

    $result = $contributor->contribute(
        findOrFailActionDescriptor(FindOrFailFixtureController::class, 'staticFindOrFail'),
    );

    expect($result)->toBe([]);
});

it('returns an empty list for a closure route without a reflected method', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect(findOrFailContributor()->contribute($descriptor))->toBe([]);
});

// endregion
