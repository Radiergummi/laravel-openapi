<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\SpecTime\AnyValue;

uses()->group('openapi');

it('returns itself from property access', function (): void {
    $stub = AnyValue::instance();

    expect($stub->anything)->toBe($stub)
        ->and($stub->some_relation->another->and_another)->toBe($stub);
});

it('returns itself from method calls regardless of arity', function (): void {
    $stub = AnyValue::instance();

    expect($stub->whatever())->toBe($stub)
        ->and($stub->withArgs(1, 'two', [3]))->toBe($stub)
        ->and($stub->chained()->calls()->terminate()->somewhere())->toBe($stub);
});

it('stringifies to the empty string', function (): void {
    expect((string) AnyValue::instance())->toBe('');
});

it('serialises to null for json', function (): void {
    expect(json_encode(AnyValue::instance()))->toBe('null');
});

it('counts as zero', function (): void {
    expect(count(AnyValue::instance()))->toBe(0);
});

it('iterates as empty', function (): void {
    $items = [];

    foreach (AnyValue::instance() as $item) {
        $items[] = $item;
    }

    expect($items)->toBe([]);
});

it('responds to array-access reads by returning itself; writes/unsets are no-ops', function (): void {
    $stub = AnyValue::instance();

    expect(isset($stub['x']))->toBeFalse()
        ->and($stub['x'])->toBe($stub);

    $stub['x'] = 'ignored';
    unset($stub['x']);

    expect($stub['x'])->toBe($stub);
});

it('reports isset() as true for any property access', function (): void {
    $stub = AnyValue::instance();

    expect(isset($stub->anything))->toBeTrue()
        ->and(isset($stub->nested->deeper))->toBeTrue();
});

it('returns the same singleton from instance()', function (): void {
    expect(AnyValue::instance())->toBe(AnyValue::instance());
});

it('survives use as an argument to Rule::in', function (): void {
    $rule = Illuminate\Validation\Rule::in([AnyValue::instance()]);

    expect((string) $rule)->toContain('in:');
});

it('survives use as an argument to Rule::unique()->ignore()', function (): void {
    $rule = Illuminate\Validation\Rule::unique('users')->ignore(AnyValue::instance());

    expect((string) $rule)->toContain('unique:users');
});
