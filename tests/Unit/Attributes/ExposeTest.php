<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\Expose;

it('accepts no arguments', function (): void {
    $expose = new Expose();
    expect($expose->only)
        ->toBeNull()
        ->and($expose->except)->toBeNull();
});

it('stores the only list', function (): void {
    $expose = new Expose(only: ['staging']);
    expect($expose->only)
        ->toBe(['staging'])
        ->and($expose->except)->toBeNull();
});

it('stores the except list', function (): void {
    $expose = new Expose(except: ['production']);
    expect($expose->except)
        ->toBe(['production'])
        ->and($expose->only)->toBeNull();
});

it('throws LogicException when both only and except are supplied', function (): void {
    new Expose(only: ['staging'], except: ['production']);
})->throws(LogicException::class, '#[Expose]');
