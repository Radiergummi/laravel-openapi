<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Attributes\Description;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Attributes\Summary;

uses()->group('openapi');

class SummaryDescriptionController extends Controller
{
    #[Summary('Search products')]
    #[Description('Returns paginated results matching the given query.')]
    public function search(): JsonResponse
    {
        return new JsonResponse();
    }

    /**
     * Docblock summary.
     *
     * Docblock description.
     */
    #[Summary('Attribute summary wins')]
    public function override(): JsonResponse
    {
        return new JsonResponse();
    }
}

#[Summary('Class-level summary')]
#[Description('Class-level description.')]
class ClassLevelSummaryDescriptionController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

class SummaryWithOperationController extends Controller
{
    #[Operation(summary: 'Operation summary', description: 'Operation description.')]
    #[Summary('Standalone summary')]
    #[Description('Standalone description.')]
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

#[Summary('Class standalone summary')]
#[Description('Class standalone description.')]
class MethodBeatsClassSummaryDescriptionController extends Controller
{
    #[Operation(summary: 'Method op summary', description: 'Method op description.')]
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

#[Summary('Class standalone summary')]
#[Description('Class standalone description.')]
class MethodDocblockBeatsClassAttrController extends Controller
{
    /**
     * Method docblock summary.
     *
     * Method docblock description.
     */
    public function index(): JsonResponse
    {
        return new JsonResponse();
    }
}

#[Summary('Invocable class summary')]
#[Description('Invocable class description.')]
class InvocableSummaryDescriptionController extends Controller
{
    /**
     * Invocable docblock summary.
     *
     * Invocable docblock description.
     */
    public function __invoke(): JsonResponse
    {
        return new JsonResponse();
    }
}

it('uses #[Summary] and #[Description] attributes for operation metadata', function (): void {
    RouteFacade::get(
        '/oa-sd/search',
        [SummaryDescriptionController::class, 'search'],
    );

    $operation = generateSpec()['paths']['/oa-sd/search']['get'];

    expect($operation['summary'])->toBe('Search products')
        ->and($operation['description'])->toBe('Returns paginated results matching the given query.');
});

it('lets method-level #[Summary] override the docblock', function (): void {
    RouteFacade::get(
        '/oa-sd/override',
        [SummaryDescriptionController::class, 'override'],
    );

    $operation = generateSpec()['paths']['/oa-sd/override']['get'];

    expect($operation['summary'])->toBe('Attribute summary wins')
        ->and($operation['description'])->toBe('Docblock description.');
});

it('reads #[Summary] and #[Description] from the controller class', function (): void {
    RouteFacade::get(
        '/oa-sd/class-level',
        [ClassLevelSummaryDescriptionController::class, 'index'],
    );

    $operation = generateSpec()['paths']['/oa-sd/class-level']['get'];

    expect($operation['summary'])->toBe('Class-level summary')
        ->and($operation['description'])->toBe('Class-level description.');
});

it('prefers standalone #[Summary]/#[Description] over #[Operation] fields', function (): void {
    RouteFacade::get(
        '/oa-sd/combined',
        [SummaryWithOperationController::class, 'index'],
    );

    $operation = generateSpec()['paths']['/oa-sd/combined']['get'];

    expect($operation['summary'])->toBe('Standalone summary')
        ->and($operation['description'])->toBe('Standalone description.');
});

it('lets method-level #[Operation] outrank class-level standalone attributes', function (): void {
    RouteFacade::get(
        '/oa-sd/method-beats-class',
        [MethodBeatsClassSummaryDescriptionController::class, 'index'],
    );

    $operation = generateSpec()['paths']['/oa-sd/method-beats-class']['get'];

    expect($operation['summary'])->toBe('Method op summary')
        ->and($operation['description'])->toBe('Method op description.');
});

it('lets a method docblock outrank class-level standalone attributes', function (): void {
    RouteFacade::get(
        '/oa-sd/method-docblock-beats-class',
        [MethodDocblockBeatsClassAttrController::class, 'index'],
    );

    $operation = generateSpec()['paths']['/oa-sd/method-docblock-beats-class']['get'];

    expect($operation['summary'])->toBe('Method docblock summary.')
        ->and($operation['description'])->toBe('Method docblock description.');
});

it('reads the __invoke docblock as the action docblock, outranking class-level attributes', function (): void {
    RouteFacade::get(
        '/oa-sd/invocable',
        InvocableSummaryDescriptionController::class,
    );

    $operation = generateSpec()['paths']['/oa-sd/invocable']['get'];

    // A single-action controller's action is its __invoke() method, so its docblock describes the
    // operation — same precedence as any method docblock over class-level attributes.
    expect($operation['summary'])->toBe('Invocable docblock summary.')
        ->and($operation['description'])->toBe('Invocable docblock description.');
});
