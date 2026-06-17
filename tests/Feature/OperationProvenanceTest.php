<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\Summary;

use function str_contains;

uses()->group('openapi');

class ProvenanceFlightController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }

    public function store(): JsonResponse
    {
        return new JsonResponse();
    }

    #[Summary('Find one flight')]
    public function show(string $flight): JsonResponse
    {
        return new JsonResponse();
    }

    #[Response(status: 201, description: 'Created synchronously')]
    public function update(string $flight): JsonResponse
    {
        return new JsonResponse();
    }

    #[Operation(tags: ['custom'], replace: true)]
    public function destroy(string $flight): JsonResponse
    {
        return new JsonResponse();
    }
}

class ProvenanceDocblockController extends Controller
{
    /**
     * Browse the flight catalogue.
     */
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

class ProvenancePlainController extends Controller
{
    public function arbitrary(): JsonResponse
    {
        return new JsonResponse();
    }
}

/**
 * Runs `openapi:why` and returns the captured output. `expectsOutputToContain` mangles its buffer
 * when chained with multibyte arrows (← →), so we assert against the raw output instead.
 */
function whyOutput(string $route, bool $fields = true): string
{
    $arguments = ['route' => $route];

    if ($fields) {
        $arguments['--fields'] = true;
    }

    Artisan::call('openapi:why', $arguments);

    return Artisan::output();
}

beforeEach(function (): void {
    RouteFacade::apiResource('flights', ProvenanceFlightController::class);
    app()->forgetScopedInstances();
});

it('reports convention-derived status for a resourceful store action', function (): void {
    $output = whyOutput('flights.store');

    expect($output)
        ->toContain('Fields:')
        ->toContain('status')
        ->toContain('201')
        ->toContain('ResourceConventionResolver (store → POST)');
});

it('reports convention-derived summary for a resourceful index action', function (): void {
    $output = whyOutput('flights.index');

    expect($output)
        ->toContain('summary')
        ->toContain('List ProvenanceFlights')
        ->toContain('ResourceConventionResolver (index → GET)');
});

it('shows an attribute summary winning over a superseded convention', function (): void {
    $output = whyOutput('flights.show');

    expect($output)
        ->toContain('Find one flight')
        ->toContain('#[Summary] (method)')
        ->toContain("superseded: convention 'Show ProvenanceFlight'");
});

it('reports a docblock summary as the winning source', function (): void {
    RouteFacade::get('catalogue', [ProvenanceDocblockController::class, 'index'])
        ->name('catalogue.index');
    app()->forgetScopedInstances();

    $output = whyOutput('catalogue.index');

    expect($output)
        ->toContain('Browse the flight catalogue.')
        ->toContain('docblock');
});

it('reports an explicit #[Response] status as the source, not the convention', function (): void {
    $output = whyOutput('flights.update');

    expect($output)
        ->toContain('status')
        ->toContain('201')
        ->toContain('#[Response] (method)');
});

it('degrades to default status and absent summary when no convention matches', function (): void {
    RouteFacade::get('misc', [ProvenancePlainController::class, 'arbitrary'])
        ->name('misc.arbitrary');
    app()->forgetScopedInstances();

    $output = whyOutput('misc.arbitrary');

    expect($output)
        ->toContain('status')
        ->toContain('200')
        ->toContain('default')
        // No convention and no summary attribute: summary is absent from the field block.
        ->and(str_contains($output, 'summary'))->toBeFalse();
});

it('reports a replacing #[Operation(tags)] as the tag source', function (): void {
    $output = whyOutput('flights.destroy');

    expect($output)
        ->toContain('tags')
        ->toContain('custom')
        ->toContain('#[Operation] (replace)');
});

it('reports a controller-derived tag when no tag attribute is present', function (): void {
    $output = whyOutput('flights.index');

    expect($output)
        ->toContain('tags')
        ->toContain('ProvenanceFlights')
        ->toContain('controller-derived');
});

it('prints no Fields block without the --fields flag', function (): void {
    $output = whyOutput('flights.index', fields: false);

    expect(str_contains($output, 'Fields:'))->toBeFalse();
});
