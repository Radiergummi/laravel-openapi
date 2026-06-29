<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestRulesReader;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery\SearchFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery\ThrowingRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\ComputedRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\DynamicRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\VariableReturnFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;

uses()->group('openapi');

beforeEach(function (): void {
    $this->reader = new FormRequestRulesReader();
});

it('returns the raw, unmapped rules array', function (): void {
    $result = $this->reader->read(SimpleFormRequest::class);

    expect($result->degradeReason)->toBeNull()
        ->and($result->rules)->toBe([
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'name' => ['required', 'string', 'max:100'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'note' => ['nullable', 'string'],
        ]);
});

it('reads a search FormRequest with nested and array rules', function (): void {
    $result = $this->reader->read(SearchFormRequest::class);

    expect($result->rules)->toHaveKeys(['term', 'page', 'filter.name', 'ids.*']);
});

it('degrades when rules() throws and the body has no static literal to fall back to', function (): void {
    $result = $this->reader->read(ThrowingRulesFormRequest::class);

    expect($result->rules)->toBeNull()
        ->and($result->degradeReason)->toContain('rules() depends on runtime state')
        ->and($result->degradeReason)->toContain('no static rules() literal');
});

it('falls back to the static rules() literal when invocation throws', function (): void {
    $result = $this->reader->read(VariableReturnFormRequest::class);

    expect($result->degradeReason)->toBeNull()
        ->and($result->rules)->toBe([
            'action' => 'sometimes|string',
            'ids' => 'required|array',
            'send_email' => 'sometimes|boolean',
        ]);
});

it('still degrades when invocation throws and rules() is genuinely dynamic', function (): void {
    $result = $this->reader->read(DynamicRulesFormRequest::class);

    expect($result->rules)->toBeNull()
        ->and($result->degradeReason)->toContain('no static rules() literal');
});

it('uses the primary invocation path when rules() returns a computed literal cleanly', function (): void {
    $result = $this->reader->read(ComputedRulesFormRequest::class);

    expect($result->degradeReason)->toBeNull()
        ->and($result->rules)->toBe(['a' => 'required', 'b' => 'string']);
});

it('degrades when the class declares no rules() method', function (): void {
    $noRules = new class () extends Illuminate\Foundation\Http\FormRequest {};

    $result = $this->reader->read($noRules::class);

    expect($result->rules)->toBeNull()
        ->and($result->degradeReason)->toContain('does not declare a rules() method');
});
