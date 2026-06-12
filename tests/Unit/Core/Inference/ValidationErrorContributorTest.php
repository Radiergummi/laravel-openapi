<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ValidationErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// Inline fixture controllers for reflection-based tests

class ControllerWithNoFormRequest
{
    public function store(string $name, int $count): void {}
}

class ControllerWithInlineValidateCall
{
    public function store(Request $request): void
    {
        $request->validate(['name' => 'required|string']);
    }
}

class ControllerWithDegradedInlineValidateCall
{
    public function store(Request $request): void
    {
        $request->validate(array_merge(['name' => 'required'], $request->all()));
    }
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
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithNoFormRequest::class, 'store'),
    );

    expect($result)->toBe([]);
});

// endregion

// region Inline validate() call in the method body

it('returns one 422 descriptor when the method body contains a whitelisted inline validate() call', function (): void {
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithInlineValidateCall::class, 'store'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(422)
        ->and($result[0]->exceptionClass)->toBe(ValidationException::class);
});

it('returns the 422 descriptor even when the inline validate() rules cannot be read (degraded scan)', function (): void {
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithDegradedInlineValidateCall::class, 'store'),
    );

    expect($result)
        ->toHaveCount(1)
        ->and($result[0]->status)->toBe(422);
});

// endregion

// region One FormRequest subclass parameter

it('returns one 422 descriptor when the action has a FormRequest subclass parameter', function (): void {
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
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
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
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
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
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
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: []);

    $result = $contributor->contribute(
        ActionDescriptorFactory::forControllerMethod(ControllerWithFormRequestSubclass::class, 'store'),
    );

    expect($result)->toBe([]);
});

// endregion

// region method === null (closure route)

it('returns an empty list when descriptor method is null', function (): void {
    $contributor = new ValidationErrorContributor(inlineValidatorRulesReader: new InlineValidatorRulesReader(new MethodBodyScanner()), exceptionMap: [
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
    ]);

    $result = $contributor->contribute(
        ActionDescriptorFactory::withMiddleware([]),
    );

    expect($result)->toBe([]);
});

// endregion
