<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Attributes\Tag;

uses()->group('openapi');

#[Operation(tags: ['ExtraTag'])]
class MergeTagController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

class StandaloneTagController extends Controller
{
    #[Tag('Beta')]
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

#[Operation(tags: ['Replacement'], replace: true)]
class ReplaceTagController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('OAPI-020: Operation(tags:) merges with namespace-derived tag by default', function (): void {
    RouteFacade::get(
        '/oa-p2/merge-tag',
        [MergeTagController::class, 'index'],
    );

    $spec = generateSpec();

    $tags = $spec['paths']['/oa-p2/merge-tag']['get']['tags'] ?? [];

    expect($tags)->toContain('ExtraTag');
});

it('OAPI-020: Operation(tags:, replace: true) discards namespace-derived tags', function (): void {
    RouteFacade::get(
        '/oa-p2/replace-tag',
        [ReplaceTagController::class, 'index'],
    );

    $spec = generateSpec();

    $tags = $spec['paths']['/oa-p2/replace-tag']['get']['tags'] ?? [];

    expect($tags)->toBe(['Replacement']);
});

it('surfaces a standalone #[Tag] on the operation', function (): void {
    RouteFacade::get(
        '/oa-86/tag',
        [StandaloneTagController::class, 'index'],
    );

    $tags = generateSpec()['paths']['/oa-86/tag']['get']['tags'] ?? [];

    expect($tags)->toContain('Beta');
});
