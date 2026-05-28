<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\Tag;

uses()->group('attributes', 'openapi');

enum OperationTag: string
{
    case Identity = 'Identity';
    case Billing = 'Billing';
}

enum NumericOperationTag: int
{
    case High = 1;
    case Low = 2;
}

it('accepts a string tag name', function (): void {
    $tag = new Tag('Identity');

    expect($tag->value())->toBe('Identity');
});

it('rejects an int-backed enum at construction time', function (): void {
    expect(fn(): Tag => new Tag(NumericOperationTag::High))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a string-backed enum case and exposes its value', function (): void {
    $tag = new Tag(OperationTag::Identity);

    expect($tag->value())->toBe('Identity');
});
