<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\CookieParam;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Tests\Fixtures\VendorExtensionFixtureData;

use function array_column;
use function array_filter;

uses()->group('openapi', 'plugin:spatie-data');

class VendorExtensionFixtureController extends Controller
{
    public function store(VendorExtensionFixtureData $data): VendorExtensionFixtureData
    {
        return $data;
    }

    #[QueryParam(name: 'sort', type: 'string', x: ['x-ui-control' => 'dropdown'])]
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }

    public function show(
        #[PathParam(description: 'The widget.', x: ['x-resource' => 'widget'])]
        string $widget,
    ): JsonResponse {
        return new JsonResponse();
    }

    #[CookieParam(name: 'session', x: ['x-sensitive' => true])]
    public function cookie(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('emits a #[ResponseField] x-* extension on the response component schema property', function (): void {
    Route::post('/vendor-ext/store', [VendorExtensionFixtureController::class, 'store']);

    $props = generateSpec()['components']['schemas']['VendorExtensionFixtureData']['properties'];

    expect($props['id'])->toHaveKey('x-internal-id')
        ->and($props['id']['x-internal-id'])->toBe('abc')
        ->and($props['id'])->not->toHaveKey('x-x-internal-id');
});

it('emits #[RequestField] x-* extensions (including a nested array value)', function (): void {
    Route::post('/vendor-ext/store', [VendorExtensionFixtureController::class, 'store']);

    $props = generateSpec()['components']['schemas']['VendorExtensionFixtureData']['properties'];

    expect($props['weight']['x-ui-widget'])->toBe('slider')
        ->and($props['weight']['x-meta'])->toBe(['min' => 1]);
});

it('emits a #[QueryParam] x-* extension on the parameter schema', function (): void {
    Route::get('/vendor-ext/index', [VendorExtensionFixtureController::class, 'index']);

    $operation = generateSpec()['paths']['/vendor-ext/index']['get'];

    $sort = array_column(
        array_filter(
            $operation['parameters'] ?? [],
            static fn(array $parameter): bool => ($parameter['in'] ?? null) === 'query',
        ),
        null,
        'name',
    )['sort'] ?? null;

    expect($sort)->not->toBeNull()
        ->and($sort['schema']['x-ui-control'])->toBe('dropdown')
        ->and($sort['schema'])->not->toHaveKey('x-x-ui-control');
});

it('emits a #[PathParam] x-* extension on the parameter schema', function (): void {
    Route::get('/vendor-ext/{widget}', [VendorExtensionFixtureController::class, 'show']);

    $operation = generateSpec()['paths']['/vendor-ext/{widget}']['get'];

    $widget = array_column(
        array_filter(
            $operation['parameters'] ?? [],
            static fn(array $parameter): bool => ($parameter['in'] ?? null) === 'path',
        ),
        null,
        'name',
    )['widget'] ?? null;

    expect($widget)->not->toBeNull()
        ->and($widget['schema']['x-resource'])->toBe('widget')
        ->and($widget['schema'])->not->toHaveKey('x-x-resource');
});

it('emits a #[CookieParam] x-* extension on the parameter schema', function (): void {
    Route::get('/vendor-ext/cookie', [VendorExtensionFixtureController::class, 'cookie']);

    $operation = generateSpec()['paths']['/vendor-ext/cookie']['get'];

    $session = array_column(
        array_filter(
            $operation['parameters'] ?? [],
            static fn(array $parameter): bool => ($parameter['in'] ?? null) === 'cookie',
        ),
        null,
        'name',
    )['session'] ?? null;

    expect($session)->not->toBeNull()
        ->and($session['schema']['x-sensitive'])->toBeTrue()
        ->and($session['schema'])->not->toHaveKey('x-x-sensitive');
});
