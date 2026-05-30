<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Core\Support\SpecTime\AnyValue;
use Radiergummi\OpenApi\Core\Support\SpecTime\SpecTimeRequest;

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
