<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\Hide;

it('accepts no arguments and stores null for both scopes', function (): void {
    $hide = new Hide();
    expect($hide->only)->toBeNull()
        ->and($hide->except)->toBeNull();
});

it('stores the only list', function (): void {
    $hide = new Hide(only: ['production', 'staging']);
    expect($hide->only)->toBe(['production', 'staging'])
        ->and($hide->except)->toBeNull();
});

it('stores the except list', function (): void {
    $hide = new Hide(except: ['local']);
    expect($hide->except)->toBe(['local'])
        ->and($hide->only)->toBeNull();
});

it('throws LogicException when both only and except are supplied', function (): void {
    new Hide(only: ['production'], except: ['local']);
})->throws(LogicException::class, '#[Hide]');
