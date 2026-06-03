<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Attributes\Spec;

/**
 * Registers v1 and v2 named specs whose `match` prefixes BOTH catch the fixture route, so the
 * test proves #[Spec] overrides match config: the route is pinned to v1 and stays out of v2
 * despite v2's prefix matching it. defineEnvironment() runs before BootProviders, so the
 * SpecRegistry is constructed with these specs.
 */
trait WithV1V2SpecConfig
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('openapi.specs', [
            'v1' => ['match' => ['prefix' => 'oa-86-spec/*']],
            'v2' => ['match' => ['prefix' => 'oa-86-spec/*']],
        ]);
    }
}

uses(WithV1V2SpecConfig::class)->group('openapi');

class SpecAttributeFixtureController extends Controller
{
    #[Spec('v1')]
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('pins a #[Spec(\'v1\')] route into v1 and out of v2 (overriding match) and default', function (): void {
    RouteFacade::get('/oa-86-spec/pinned', [SpecAttributeFixtureController::class, 'index']);

    expect(generateSpec('v1')['paths'] ?? [])->toHaveKey('/oa-86-spec/pinned')
        ->and(generateSpec('v2')['paths'] ?? [])->not->toHaveKey('/oa-86-spec/pinned')
        ->and(generateSpec()['paths'] ?? [])->not->toHaveKey('/oa-86-spec/pinned');
});
