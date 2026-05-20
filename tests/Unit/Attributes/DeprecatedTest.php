<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\Deprecated;

uses()->group('openapi');

it('stores the optional reason', function (): void {
    expect((new Deprecated())->reason)->toBeNull();
    expect((new Deprecated(reason: 'use Foo instead'))->reason)->toBe('use Foo instead');
});

it('targets methods, functions, properties, parameters, and class constants', function (): void {
    $reflection = new ReflectionClass(Deprecated::class);
    $attribute  = $reflection->getAttributes(Attribute::class)[0]->newInstance();

    $expected = Attribute::TARGET_METHOD
        | Attribute::TARGET_FUNCTION
        | Attribute::TARGET_PROPERTY
        | Attribute::TARGET_PARAMETER
        | Attribute::TARGET_CLASS_CONSTANT;

    expect($attribute->flags)->toBe($expected);
});

it('is final and readonly', function (): void {
    $reflection = new ReflectionClass(Deprecated::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});
