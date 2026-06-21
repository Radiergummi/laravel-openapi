<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestRulesReader;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery\SearchFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery\ThrowingRulesFormRequest;
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

it('degrades with a reason when rules() throws', function (): void {
    $result = $this->reader->read(ThrowingRulesFormRequest::class);

    expect($result->rules)->toBeNull()
        ->and($result->degradeReason)->toBe('rules() depends on runtime state');
});

it('degrades when the class declares no rules() method', function (): void {
    $noRules = new class () extends Illuminate\Foundation\Http\FormRequest {};

    $result = $this->reader->read($noRules::class);

    expect($result->rules)->toBeNull()
        ->and($result->degradeReason)->toContain('does not declare a rules() method');
});
