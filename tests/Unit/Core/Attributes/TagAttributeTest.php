<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\Tag;

uses()->group('attributes', 'openapi');

enum OperationTag: string
{
    case Identity = 'Identity';
    case Billing = 'Billing';
}

it('accepts a string tag name', function (): void {
    $tag = new Tag('Identity');

    expect($tag->value())->toBe('Identity');
});

it('accepts a BackedEnum case and exposes its string value', function (): void {
    $tag = new Tag(OperationTag::Identity);

    expect($tag->value())->toBe('Identity');
});
