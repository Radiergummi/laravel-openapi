<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\SpatieData\DataSyntheticPayloadBuilder;
use Spatie\LaravelData\Support\DataConfig;
use Radiergummi\OpenApi\Tests\Fixtures\AddressFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\CycleAFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\NestedParentData;
use Radiergummi\OpenApi\Tests\Fixtures\PlainArrayData;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Radiergummi\OpenApi\Tests\Fixtures\ValidationRulesFixtureData;

uses()->group('openapi', 'plugin:spatie-data');

function makeBuilder(): DataSyntheticPayloadBuilder
{
    return new DataSyntheticPayloadBuilder(app(DataConfig::class));
}

it('emits null for each scalar property', function (): void {
    $payload = makeBuilder()->build(ScalarOnlyData::class);

    expect($payload)
        ->toHaveKey('name', null)
        ->toHaveKey('count', null)
        ->toHaveKey('score', null);
});

it('recurses into nested Data objects one level deep', function (): void {
    $payload = makeBuilder()->build(NestedParentData::class);

    expect($payload)->toHaveKey('child')
        ->and($payload['child'])->toBeArray()
        ->and($payload['child'])->toHaveKey('name', null)
        ->and($payload['child'])->toHaveKey('count', null);
});

it('emits a single-item array for plain array properties', function (): void {
    $payload = makeBuilder()->build(PlainArrayData::class);

    expect($payload)->toHaveKey('tags')
        ->and($payload['tags'])->toBe([null]);
});

it('emits correct keys for ValidationRulesFixtureData', function (): void {
    $payload = makeBuilder()->build(ValidationRulesFixtureData::class);

    expect($payload)
        ->toHaveKey('name')
        ->toHaveKey('description')
        ->toHaveKey('score')
        ->toHaveKey('email')
        ->toHaveKey('tags')
        ->toHaveKey('status')
        ->toHaveKey('address');

    // Nested Data → sub-array with address properties.
    expect($payload['address'])->toBeArray()
        ->and($payload['address'])->toHaveKey('street')
        ->and($payload['address'])->toHaveKey('city');
});

it('respects the cycle guard — a self-referencing class does not loop infinitely', function (): void {
    // AddressFixtureData references itself transitively through NestedParentData;
    // building it must terminate and return a non-empty payload.
    $result = makeBuilder()->build(AddressFixtureData::class);

    expect($result)->toBeArray();
});

it('handles indirect cycles (A → B → A) without infinite recursion', function (): void {
    $result = makeBuilder()->build(CycleAFixtureData::class);

    // Top-level A: must have label (scalar) and b (nested B).
    expect($result)
        ->toHaveKey('label', null)
        ->toHaveKey('b');

    // Nested B: must have tag and a.
    expect($result['b'])
        ->toBeArray()
        ->toHaveKey('tag', null)
        ->toHaveKey('a');

    // Third-level A is short-circuited by the cycle guard — emits [].
    expect($result['b']['a'])->toBe([]);
});
