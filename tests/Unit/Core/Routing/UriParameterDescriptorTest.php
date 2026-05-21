<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Routing\UriParameterDescriptor;
use Radiergummi\OpenApi\Core\Routing\WhereKind;
use Symfony\Component\TypeInfo\Type;

uses()->group('routing', 'openapi');

it('captures every constructor argument verbatim', function (): void {
    $type = Type::string();

    $descriptor = new UriParameterDescriptor(
        name: 'project',
        type: $type,
        optional: false,
        whereConstraint: '[a-f0-9-]+',
        whereKind: WhereKind::Uuid,
        modelClass: stdClass::class,
        routeKeyName: 'uuid',
        enumCases: null,
    );

    expect($descriptor->name)->toBe('project')
        ->and($descriptor->type)->toBe($type)
        ->and($descriptor->optional)->toBeFalse()
        ->and($descriptor->whereConstraint)->toBe('[a-f0-9-]+')
        ->and($descriptor->whereKind)->toBe(WhereKind::Uuid)
        ->and($descriptor->modelClass)->toBe(stdClass::class)
        ->and($descriptor->routeKeyName)->toBe('uuid')
        ->and($descriptor->enumCases)->toBeNull();
});

it('accepts null for every optional field and a list for enum cases', function (): void {
    $descriptor = new UriParameterDescriptor(
        name: 'status',
        type: Type::string(),
        optional: true,
        whereConstraint: null,
        whereKind: null,
        modelClass: null,
        routeKeyName: null,
        enumCases: ['draft', 'published'],
    );

    expect($descriptor->optional)->toBeTrue()
        ->and($descriptor->whereConstraint)->toBeNull()
        ->and($descriptor->whereKind)->toBeNull()
        ->and($descriptor->modelClass)->toBeNull()
        ->and($descriptor->routeKeyName)->toBeNull()
        ->and($descriptor->enumCases)->toBe(['draft', 'published']);
});
