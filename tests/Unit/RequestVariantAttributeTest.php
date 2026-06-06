<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\RequestVariant;

uses()->group('openapi');

it('captures inline fields', function (): void {
    $variant = new RequestVariant('aws', fields: [new RequestField('region', required: true)]);

    expect($variant->value)->toBe('aws')
        ->and($variant->schema)->toBeNull()
        ->and($variant->fields)->toHaveCount(1)
        ->and($variant->fields[0]->name)->toBe('region')
        ->and($variant->isMalformed())->toBeFalse();
});

it('captures a class-string branch', function (): void {
    // Any class-string is accepted; the attribute does not validate the target (the resolver
    // resolves it via the ref chain). RequestField::class is used only as a real class-string.
    $variant = new RequestVariant('custom', schema: RequestField::class);

    expect($variant->schema)->toBe(RequestField::class)
        ->and($variant->fields)->toBe([])
        ->and($variant->isMalformed())->toBeFalse();
});

it('is malformed when neither or both branch forms are given', function (): void {
    expect((new RequestVariant('x'))->isMalformed())->toBeTrue()
        ->and((new RequestVariant('x', schema: RequestField::class, fields: [new RequestField('a')]))->isMalformed())->toBeTrue();
});
