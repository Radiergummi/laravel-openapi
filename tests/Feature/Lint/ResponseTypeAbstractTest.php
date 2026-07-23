<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Lint;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;

uses()->group('openapi', 'lint');

abstract class AppAbstractResourceBaseFixture extends JsonResource {}

class ConcreteThingResourceFixture extends JsonResource
{
    /**
     * @param mixed $request
     *
     * @return array{id: int, name: string}
     */
    public function toArray($request): array
    {
        return ['id' => 1, 'name' => 'x'];
    }
}

class ConcreteChildResourceFixture extends AppAbstractResourceBaseFixture {}

/** An opaque source the return-expression reader cannot resolve to a concrete resource. */
class ResourceFetchingService
{
    public function one(): JsonResource
    {
        return new ConcreteThingResourceFixture(null);
    }

    public function abstractOne(): AppAbstractResourceBaseFixture
    {
        return new ConcreteChildResourceFixture(null);
    }
}

class ResponseTypeAbstractController
{
    // Declared as the framework base, body opaque → resolves to the empty JsonResource component.
    public function bareBase(ResourceFetchingService $service): JsonResource
    {
        return $service->one();
    }

    // Declared as an app-defined abstract base, body opaque.
    public function appAbstract(ResourceFetchingService $service): AppAbstractResourceBaseFixture
    {
        return $service->abstractOne();
    }

    // Return-expression reader resolves the concrete subclass → non-empty component.
    public function concrete(): ConcreteThingResourceFixture
    {
        return new ConcreteThingResourceFixture(null);
    }

    // Resolved collection: the element resource is read from the collection() call.
    public function resolvedCollection(): AnonymousResourceCollection
    {
        return ConcreteThingResourceFixture::collection([]);
    }
}

/**
 * @return list<Finding>
 */
function responseTypeAbstractFindings(string $uri): array
{
    return app(LintRunner::class)->run(new LintOptions(
        only: ['operation.response-type-abstract'],
        uriGlob: $uri,
    ))->findings;
}

it('flags a response typed as the framework resource base', function (): void {
    Route::get('rta/bare', [ResponseTypeAbstractController::class, 'bareBase'])->name('rta.bare');
    app()->forgetScopedInstances();

    $findings = responseTypeAbstractFindings('rta/bare');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.response-type-abstract')
        ->and($findings[0]->severity)->toBe(Severity::Underspecified)
        ->and($findings[0]->message)->toContain('GET /rta/bare')
        ->and($findings[0]->message)->toContain('JsonResource')
        ->and($findings[0]->message)->toContain('narrow');
});

it('flags a response typed as an app-defined abstract resource base', function (): void {
    Route::get('rta/appabs', [ResponseTypeAbstractController::class, 'appAbstract'])->name('rta.appabs');
    app()->forgetScopedInstances();

    $findings = responseTypeAbstractFindings('rta/appabs');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('AppAbstractResourceBaseFixture');
});

it('does not fire', function (string $method, string $uri): void {
    Route::get($uri, [ResponseTypeAbstractController::class, $method])->name("rta.{$method}");
    app()->forgetScopedInstances();

    expect(responseTypeAbstractFindings($uri))->toBe([]);
})->with([
    'concrete resource subclass' => ['concrete', 'rta/concrete'],
    'resolved anonymous collection' => ['resolvedCollection', 'rta/coll'],
]);
