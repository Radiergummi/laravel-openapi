<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\StatusFixtureEnum;
use Symfony\Component\TypeInfo\Type;

// TODO(tracker §5): these tests call JsonSchemaFromType directly; rewrite to
// drive openapi:generate against a route returning a response containing the enum.

uses()->group('openapi');

it('OAPI-034: BackedEnum with per-case PHPDoc produces a markdown description on the schema', function (): void {
    $schemaFromType = new JsonSchemaFromType(new NullLogger());
    $schema = $schemaFromType->fromType(Type::enum(StatusFixtureEnum::class));

    expect($schema->description)->toBeString()
        ->and($schema->description)->toContain('active')
        ->and($schema->description)->toContain('Active and visible to all users.')
        ->and($schema->description)->toContain('archived')
        ->and($schema->description)->toContain('Archived and hidden from normal views.')
        ->and($schema->description)->toContain('draft')
        ->and($schema->description)->toContain('Draft that has not been published yet.');
});

it('OAPI-034: each enum case description line starts with a backtick-quoted value', function (): void {
    $schemaFromType = new JsonSchemaFromType(new NullLogger());
    $schema = $schemaFromType->fromType(Type::enum(StatusFixtureEnum::class));

    $lines = explode("\n", $schema->description);

    expect($lines)->toHaveCount(3);

    foreach ($lines as $line) {
        expect($line)->toMatch('/^- `[^`]+`: .+/');
    }
});
