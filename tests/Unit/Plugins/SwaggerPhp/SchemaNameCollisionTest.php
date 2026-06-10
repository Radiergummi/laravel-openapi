<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\SchemaNameCollision;

uses()->group('openapi', 'lint');

// `component.schema-name-collision` is a registration stub — it has no visitor. The finding is
// emitted during generation by HarvestAuthoredAnnotationsStage (covered by its stage test); these
// tests pin the stub's registration contract so its identity stays stable.

it('exposes the stable rule id', function (): void {
    expect(new SchemaNameCollision()->id())->toBe('component.schema-name-collision')
        ->and(SchemaNameCollision::ID)->toBe('component.schema-name-collision');
});

it('reports a warning-level severity', function (): void {
    expect(new SchemaNameCollision()->level())->toBe(1)
        ->and(SchemaNameCollision::LEVEL)->toBe(1);
});

it('provides a non-empty description', function (): void {
    expect(new SchemaNameCollision()->description())->not->toBe('');
});

it('exposes an actionable fix hint', function (): void {
    expect(SchemaNameCollision::FIX_HINT)->toContain('@OA\Schema');
});
