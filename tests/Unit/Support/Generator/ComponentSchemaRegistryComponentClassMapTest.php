<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

uses()->group('openapi');

it('includes keys reserved via reserveKey() even when no schema has been registered yet', function (): void {
    $registry = new ComponentSchemaRegistry();

    // reserveKey populates keyToClass without storing a schema (the cycle-guard path).
    $registry->reserveKey(stdClass::class);

    $map = $registry->componentClassMap();

    expect($map)->toHaveKey('stdClass')
        ->and($map['stdClass'])->toBe(stdClass::class);
});

it('exposes a class-to-key map for registered classes, excluding named-key sentinel reservations', function (): void {
    $registry = new ComponentSchemaRegistry();

    $registry->register(stdClass::class, new OA\Schema([]));
    $registry->registerNamed('NamedOnly', new OA\Schema([]));

    $map = $registry->componentClassMap();

    // The key is the component key derived from the class basename ('stdClass'), not the schema
    // annotation value — the registry derives keys from class names.
    expect($map)->toHaveKey('stdClass')
        ->and($map['stdClass'])->toBe(stdClass::class)
        ->and($map)->not->toHaveKey('NamedOnly');
});
