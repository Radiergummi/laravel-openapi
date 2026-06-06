<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\RouteModelBindingErrorContributor;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// Inline fixture controllers for reflection-based tests

class ControllerWithBoundModel
{
    public function show(Article $article): void {}
}

class ControllerWithScalarParameters
{
    public function index(string $filter, int $page): void {}
}

class ControllerWithTwoBoundModels
{
    public function show(Article $article, User $user): void {}
}

class ControllerWithUnboundModel
{
    public function show(Article $article): void {}
}

class ControllerWithCustomKeyBinding
{
    public function show(Article $article): void {}
}

class ControllerWithModelBaseClass
{
    public function show(Model $model): void {}
}

// region Bound model parameter → 404

it('returns one 404 descriptor when a URI parameter is typed as an Eloquent model', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithBoundModel::class,
            'show',
            '/articles/{article}',
        ),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404)
        ->and($result[0]->description)->toBe('Resource not found')
        ->and($result[0]->exceptionClass)->toBe(ModelNotFoundException::class);
});

// endregion

// region No model parameter → empty

it('returns an empty list when the action has only scalar parameters', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithScalarParameters::class,
            'index',
            '/articles',
        ),
    );

    expect($result)->toBe([]);
});

// endregion

// region Multiple bound models → single descriptor

it('returns one descriptor (no duplicates) when multiple URI parameters are bound models', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithTwoBoundModels::class,
            'show',
            '/articles/{article}/users/{user}',
        ),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

// endregion

// region Model parameter not present in URI → empty

it('returns an empty list when a model parameter is not a URI segment', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithUnboundModel::class,
            'show',
            '/articles',
        ),
    );

    expect($result)->toBe([]);
});

// endregion

// region Custom-key binding (`{article:slug}`) → 404

it('detects a binding declared with a custom key segment', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithCustomKeyBinding::class,
            'show',
            '/articles/{article:slug}',
        ),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(404);
});

// endregion

// region Parameter typed as the abstract Model base class → empty

it('returns an empty list when the parameter is typed as Model itself, not a subclass', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithModelBaseClass::class,
            'show',
            '/things/{model}',
        ),
    );

    expect($result)->toBe([]);
});

// endregion

// region ModelNotFoundException absent from config → empty

it('returns an empty list when ModelNotFoundException is not present in the exception map', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: []);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(
            ControllerWithBoundModel::class,
            'show',
            '/articles/{article}',
        ),
    );

    expect($result)->toBe([]);
});

// endregion

// region method === null (closure route) → empty

it('returns an empty list when descriptor method is null', function (): void {
    $contributor = new RouteModelBindingErrorContributor(exceptionMap: [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
    ]);

    $result = $contributor->contribute(ActionDescriptorFactory::withMiddleware([]));

    expect($result)->toBe([]);
});

// endregion
