<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\Deprecated;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\Summary;
use Radiergummi\OpenApi\Tests\Fixtures\ResponseFieldFixtureData;

uses()->group('openapi', 'plugin:spatie-data');

class AttributeCoverageFixtureController extends Controller
{
    public function responseField(): ResponseFieldFixtureData
    {
        return new ResponseFieldFixtureData(id: '1', name: 'widget');
    }

    #[Deprecated(reason: 'Use the v2 endpoint.')]
    public function deprecatedOperation(): JsonResponse
    {
        return new JsonResponse();
    }

    // One action stacking five distinct authoring attributes — the multi-attribute combination
    // case. The route carries auth middleware so #[PublicEndpoint] has something to override.
    #[Summary('Queue a combo job.')]
    #[Deprecated(reason: 'Superseded by /v2/combo.')]
    #[QueryParam(name: 'locale', type: 'string', description: 'Response locale.')]
    #[Response(status: 202, description: 'Accepted for processing.')]
    #[PublicEndpoint]
    public function combo(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('surfaces #[ResponseField] description and readOnly on the response component schema', function (): void {
    Route::get('/oa-56/response-field', [AttributeCoverageFixtureController::class, 'responseField']);

    $property = generateSpec()['components']['schemas']['ResponseFieldFixtureData']['properties']['id'];

    expect($property['description'])->toBe('Server-assigned identifier.')
        ->and($property['readOnly'])->toBeTrue();
});

it('emits deprecated:true on an operation carrying #[Deprecated]', function (): void {
    Route::get('/oa-56/deprecated', [AttributeCoverageFixtureController::class, 'deprecatedOperation']);

    $operation = generateSpec()['paths']['/oa-56/deprecated']['get'];

    expect($operation['deprecated'])->toBeTrue();
});

it('applies every attribute when several are stacked on one route', function (): void {
    Route::post('/oa-56/combo', [AttributeCoverageFixtureController::class, 'combo'])
        ->middleware('auth:api');

    $operation = generateSpec()['paths']['/oa-56/combo']['post'];

    $locale = array_column(
        array_filter(
            $operation['parameters'] ?? [],
            static fn(array $p): bool => ($p['in'] ?? null) === 'query',
        ),
        null,
        'name',
    )['locale'] ?? null;

    expect($operation['summary'])->toBe('Queue a combo job.')           // #[Summary]
        ->and($operation['deprecated'])->toBeTrue()                     // #[Deprecated]
        ->and($operation['security'])->toBe([])                         // #[PublicEndpoint] beats auth middleware
        ->and($locale)->not->toBeNull()                                 // #[QueryParam]
        ->and($locale['schema']['type'])->toBe('string')
        ->and($operation['responses'])->toHaveKey('202')               // #[Response(202)] becomes primary
        ->and($operation['responses'])->not->toHaveKey('200')
        ->and($operation['responses']['202']['description'])->toBe('Accepted for processing.');
});
