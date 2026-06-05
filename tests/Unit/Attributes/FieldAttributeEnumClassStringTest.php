<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\ResponseField;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\PriorityLevel;
use Radiergummi\OpenApi\Tests\Fixtures\StatusFixtureEnum;

uses()->group('attributes', 'openapi');

it('resolves a backed-enum class-string to its case values', function (): void {
    $field = new RequestField(enum: StatusFixtureEnum::class);

    expect($field->descriptor()->toOpenApi()['enum'])
        ->toBe(['active', 'archived', 'draft']);
});

it('emits the same enum schema as a hand-written literal array', function (): void {
    $fromClass = new RequestField(enum: StatusFixtureEnum::class);
    $fromArray = new RequestField(enum: ['active', 'archived', 'draft'], type: 'string');

    expect($fromClass->descriptor()->toOpenApi()['enum'])
        ->toBe($fromArray->descriptor()->toOpenApi()['enum']);
});

it('infers string type from a string-backed enum class-string', function (): void {
    $field = new RequestField(enum: StatusFixtureEnum::class);

    expect($field->descriptor()->toOpenApi()['type'])->toBe('string');
});

it('infers integer type and values from an int-backed enum class-string', function (): void {
    $field = new RequestField(enum: PriorityLevel::class);

    $schema = $field->descriptor()->toOpenApi();

    expect($schema['type'])->toBe('integer')
        ->and($schema['enum'])->toBe([1, 2, 3]);
});

it('lets an explicit type override the inferred backing type', function (): void {
    $field = new RequestField(enum: PriorityLevel::class, type: 'string');

    expect($field->descriptor()->toOpenApi()['type'])->toBe('string');
});

it('resolves the class-string uniformly across all four field attributes', function (): void {
    $fields = [
        new RequestField(enum: StatusFixtureEnum::class),
        new ResponseField(enum: StatusFixtureEnum::class),
        new QueryParam(name: 'status', enum: StatusFixtureEnum::class),
        new ResourceField('status', enum: StatusFixtureEnum::class),
    ];

    foreach ($fields as $field) {
        expect($field->descriptor()->toOpenApi()['enum'])
            ->toBe(['active', 'archived', 'draft']);
    }
});

it('still accepts a literal array without inferring a type', function (): void {
    $field = new RequestField(enum: ['a', 'b']);

    $schema = $field->descriptor()->toOpenApi();

    expect($schema['enum'])->toBe(['a', 'b'])
        ->and($schema)->not->toHaveKey('type');
});
