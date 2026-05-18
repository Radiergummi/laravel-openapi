<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Attributes\PathParam;
use Radiergummi\OpenApi\Core\Generator\SchemaDescriptor;

uses()->group('openapi');

it('is a FieldAttribute', function (): void {
    expect(new PathParam())->toBeInstanceOf(FieldAttribute::class);
});

it('maps its parameters onto the descriptor', function (): void {
    $descriptor = (new PathParam(
        description: 'The company to retrieve.',
        example: '01HFP-EXAMPLE',
        format: 'ulid',
        pattern: '^[0-9A-HJKMNP-TV-Z]{26}$',
    ))->descriptor();

    expect($descriptor)->toBeInstanceOf(SchemaDescriptor::class)
        ->and($descriptor->description)->toBe('The company to retrieve.')
        ->and($descriptor->example)->toBe('01HFP-EXAMPLE')
        ->and($descriptor->format)->toBe('ulid')
        ->and($descriptor->pattern)->toBe('^[0-9A-HJKMNP-TV-Z]{26}$')
        ->and($descriptor->title)->toBeNull()
        ->and($descriptor->type)->toBeNull();
});

it('targets parameters only', function (): void {
    $reflection = new ReflectionClass(PathParam::class);
    $attribute  = $reflection->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags)->toBe(Attribute::TARGET_PARAMETER);
});
