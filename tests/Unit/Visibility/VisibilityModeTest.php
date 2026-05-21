<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;

it('maps the "public" config string to VisibilityMode::Public', function (): void {
    expect(VisibilityMode::fromConfig('public'))->toBe(VisibilityMode::Public);
});

it('maps the "hidden" config string to VisibilityMode::Hidden', function (): void {
    expect(VisibilityMode::fromConfig('hidden'))->toBe(VisibilityMode::Hidden);
});

it('falls back to Public for unknown values', function (): void {
    expect(VisibilityMode::fromConfig('whatever'))->toBe(VisibilityMode::Public)
        ->and(VisibilityMode::fromConfig(null))->toBe(VisibilityMode::Public)
        ->and(VisibilityMode::fromConfig(42))->toBe(VisibilityMode::Public);
});
