<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Testing\SchemaContextScope;

uses()->group('openapi');

it('preserves a 3.1.0 const keyword when constructing an OA\\Schema inside the scope', function (): void {
    $json = SchemaContextScope::with(function (): string {
        $schema = new OA\Schema(['const' => 'pinned-value']);

        return json_encode($schema, JSON_THROW_ON_ERROR);
    });

    expect($json)->toContain('"const":"pinned-value"')
        ->and($json)->not->toContain('"enum"');
});

it('restores the previous global context after the scope exits', function (): void {
    $before = Generator::$context;

    SchemaContextScope::with(static fn(): null => null);

    expect(Generator::$context)->toBe($before);
});

it('restores the previous global context even when the callable throws', function (): void {
    $before = Generator::$context;

    try {
        SchemaContextScope::with(static function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Generator::$context)->toBe($before);
});
