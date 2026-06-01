<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ValidationErrorContributor;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// Inline fixture controllers for reflection-based tests

class ControllerWithNoFormRequest
{
    public function store(string $name, int $count): void {}
}

class ControllerWithFormRequestSubclass
{
    public function store(SimpleFormRequest $request): void {}
}

class ControllerWithMixedParameters
{
    public function store(int $id, SimpleFormRequest $request, string $note = ''): void {}
}

class ControllerWithFormRequestBaseClass
{
    public function store(FormRequest $request): void {}
}

// region No FormRequest parameter

it('returns an empty list when the action has no FormRequest parameter', function (): void {
    $contributor = new ValidationErrorContributor(exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithNoFormRequest::class, 'store'),
    );

    expect($result)->toBe([]);
});

// endregion

// region One FormRequest subclass parameter

it('returns one 422 descriptor when the action has a FormRequest subclass parameter', function (): void {
    $contributor = new ValidationErrorContributor(exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithFormRequestSubclass::class, 'store'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(422)
        ->and($result[0]->description)->toBe('Validation failed')
        ->and($result[0]->exceptionClass)->toBe(ValidationException::class);
});

// endregion

// region Multiple parameters, one a FormRequest subclass

it('returns one descriptor (no duplicates) when multiple parameters include a FormRequest subclass', function (): void {
    $contributor = new ValidationErrorContributor(exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithMixedParameters::class, 'store'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(422);
});

// endregion

// region FormRequest base class (not a subclass)

it('returns an empty list when the parameter is typed as FormRequest itself, not a subclass', function (): void {
    $contributor = new ValidationErrorContributor(exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithFormRequestBaseClass::class, 'store'),
    );

    expect($result)->toBe([]);
});

// endregion

// region ValidationException absent from config

it('returns an empty list when ValidationException is not present in the exception map', function (): void {
    $contributor = new ValidationErrorContributor(exceptionMap: []);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithFormRequestSubclass::class, 'store'),
    );

    expect($result)->toBe([]);
});

// endregion

// region method === null (closure route)

it('returns an empty list when descriptor method is null', function (): void {
    $contributor = new ValidationErrorContributor(exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::withMiddleware([]),
    );

    expect($result)->toBe([]);
});

// endregion
