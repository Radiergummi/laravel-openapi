<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Plugins\Core\Support\SpecTime\AnyValue;
use Radiergummi\OpenApi\Plugins\Core\Support\SpecTime\SpecTimeRequest;

uses()->group('openapi');

it('returns an instance of the requested FormRequest subclass', function (): void {
    $request = SpecTimeRequest::wire(EmptyRulesFormRequest::class);

    expect($request)->toBeInstanceOf(EmptyRulesFormRequest::class)
        ->and($request)->toBeInstanceOf(FormRequest::class);
});

it('returns AnyValue from $this->route(any name)', function (): void {
    $request = SpecTimeRequest::wire(RouteReadingFormRequest::class);

    expect($request->route('contactInfoRequest'))->toBe(AnyValue::instance())
        ->and($request->route('whatever_else'))->toBe(AnyValue::instance());
});

it('returns AnyValue from $this->user()', function (): void {
    $request = SpecTimeRequest::wire(UserReadingFormRequest::class);

    expect($request->user())->toBe(AnyValue::instance())
        ->and($request->user('guard-name'))->toBe(AnyValue::instance());
});

it('lets rules() run to completion when it reads route bindings', function (): void {
    $request = SpecTimeRequest::wire(RouteReadingFormRequest::class);

    $rules = $request->rules();

    expect($rules)->toBeArray()
        ->and($rules)->toHaveKey('request_uuid');
});

it('lets rules() run to completion when it reads the auth user', function (): void {
    $request = SpecTimeRequest::wire(UserReadingFormRequest::class);

    $rules = $request->rules();

    expect($rules)->toBeArray()
        ->and($rules)->toHaveKey('customer_id');
});

it('resolves FormRequests with constructor dependencies through the container', function (): void {
    // Bagisto-style: a FormRequest with typed constructor args is valid Laravel — the framework
    // resolves it through the container at request time. `new $class()` (zero-arg) would raise
    // ArgumentCountError; SpecTimeRequest::wire() must do the same container resolution.
    $request = SpecTimeRequest::wire(ConstructorInjectedFormRequest::class);

    expect($request)->toBeInstanceOf(ConstructorInjectedFormRequest::class)
        ->and($request->rules())->toBe(['sku' => 'required|string']);
});

final class ConstructorInjectedFormRequestDependency
{
    public string $marker = 'resolved';
}

final class ConstructorInjectedFormRequest extends FormRequest
{
    public function __construct(
        public readonly ConstructorInjectedFormRequestDependency $dependency,
    ) {
        parent::__construct();
    }

    public function rules(): array
    {
        return ['sku' => 'required|string'];
    }
}

final class EmptyRulesFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }
}

final class RouteReadingFormRequest extends FormRequest
{
    public function rules(): array
    {
        $contactInfoRequest = $this->route('contactInfoRequest');

        return [
            'request_uuid' => ['required', 'uuid', Illuminate\Validation\Rule::in([$contactInfoRequest->uuid])],
        ];
    }
}

final class UserReadingFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Illuminate\Validation\Rule::in([$this->user()->customer_id])],
        ];
    }
}
