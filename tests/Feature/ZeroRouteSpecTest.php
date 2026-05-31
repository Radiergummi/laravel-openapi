<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

uses()->group('openapi');

it('generates a valid empty OpenAPI document when no routes are registered', function (): void {
    // No Route::get(...) calls. The library's own /api/openapi.yaml route is filtered
    // out by the default SkipSelfRoutes filter, so the discoverable route set is empty.
    $spec = generateSpec();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['info'])->toBeArray()->toHaveKey('title')
        ->and($spec['paths'])->toBe([]);
});
