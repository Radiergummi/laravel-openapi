<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\Summary;

uses()->group('openapi');

class WidgetController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }

    public function show(string $widget): JsonResponse
    {
        return new JsonResponse();
    }

    public function store(): JsonResponse
    {
        return new JsonResponse();
    }

    public function update(string $widget): JsonResponse
    {
        return new JsonResponse();
    }

    public function destroy(string $widget): JsonResponse
    {
        return new JsonResponse();
    }
}

class SummaryOverrideWidgetController extends Controller
{
    #[Summary('Custom store summary')]
    public function store(): JsonResponse
    {
        return new JsonResponse();
    }
}

class ResponseOverrideWidgetController extends Controller
{
    #[Response(status: 200, description: 'Stored synchronously')]
    public function store(): JsonResponse
    {
        return new JsonResponse();
    }
}

class DocblockSummaryWidgetController extends Controller
{
    /**
     * Browse the widget catalogue.
     */
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

class MisverbedWidgetController extends Controller
{
    public function store(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('derives the conventional success status code for each resource action', function (): void {
    RouteFacade::apiResource('widgets', WidgetController::class);

    $paths = generateSpec()['paths'];

    expect($paths['/widgets']['get']['responses'])->toHaveKey('200')
        ->and($paths['/widgets']['post']['responses'])->toHaveKey('201')
        ->and($paths['/widgets/{widget}']['get']['responses'])->toHaveKey('200')
        ->and($paths['/widgets/{widget}']['put']['responses'])->toHaveKey('200')
        ->and($paths['/widgets/{widget}']['delete']['responses'])->toHaveKey('204');
});

it('emits a body-less 204 for the destroy action', function (): void {
    RouteFacade::apiResource('widgets', WidgetController::class);

    $destroy = generateSpec()['paths']['/widgets/{widget}']['delete']['responses']['204'];

    expect($destroy)->not->toHaveKey('content');
});

it('derives a method-name-and-resource summary for each resource action', function (): void {
    RouteFacade::apiResource('widgets', WidgetController::class);

    $paths = generateSpec()['paths'];

    expect($paths['/widgets']['get']['summary'])->toBe('List Widgets')
        ->and($paths['/widgets']['post']['summary'])->toBe('Create Widget')
        ->and($paths['/widgets/{widget}']['get']['summary'])->toBe('Show Widget')
        ->and($paths['/widgets/{widget}']['put']['summary'])->toBe('Update Widget')
        ->and($paths['/widgets/{widget}']['delete']['summary'])->toBe('Delete Widget');
});

it('does not apply the convention when the route verb does not match the action', function (): void {
    RouteFacade::get('/odd-widgets', [MisverbedWidgetController::class, 'store']);

    $operation = generateSpec()['paths']['/odd-widgets']['get'];

    expect($operation['responses'])->toHaveKey('200')
        ->and($operation['responses'])->not->toHaveKey('201')
        ->and($operation)->not->toHaveKey('summary');
});

it('does not apply the store convention to the GET twin of a multi-verb route', function (): void {
    RouteFacade::match(['GET', 'POST'], '/upload', [MisverbedWidgetController::class, 'store']);

    $spec = generateSpec();
    $get = $spec['paths']['/upload']['get'];
    $post = $spec['paths']['/upload']['post'];

    // GET twin must not carry the store convention (201 / "Create …").
    expect($get['responses'])->not->toHaveKey('201')
        ->and($get)->not->toHaveKey('summary');

    // POST twin must carry the store convention.
    expect($post['responses'])->toHaveKey('201');
});

it('lets an explicit #[Summary] win over the convention summary while keeping the convention status', function (): void {
    RouteFacade::post('/widgets', [SummaryOverrideWidgetController::class, 'store']);

    $operation = generateSpec()['paths']['/widgets']['post'];

    expect($operation['summary'])->toBe('Custom store summary')
        ->and($operation['responses'])->toHaveKey('201');
});

it('lets an explicit 2xx #[Response] win over the convention status while keeping the convention summary', function (): void {
    RouteFacade::post('/widgets', [ResponseOverrideWidgetController::class, 'store']);

    $operation = generateSpec()['paths']['/widgets']['post'];

    expect($operation['responses'])->toHaveKey('200')
        ->and($operation['responses'])->not->toHaveKey('201')
        ->and($operation['summary'])->toBe('Create ResponseOverrideWidget');
});

it('lets a docblock summary win over the convention summary', function (): void {
    RouteFacade::get('/widgets', [DocblockSummaryWidgetController::class, 'index']);

    $operation = generateSpec()['paths']['/widgets']['get'];

    expect($operation['summary'])->toBe('Browse the widget catalogue.');
});
