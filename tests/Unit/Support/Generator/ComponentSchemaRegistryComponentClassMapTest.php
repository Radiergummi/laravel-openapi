<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

uses()->group('openapi');

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
