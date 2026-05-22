<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Attributes;

use Radiergummi\OpenApi\Core\Attributes\Spec;

it('normalises null / no arg to ["default"]', function (): void {
    expect(new Spec()->names)->toBe(['default'])
        ->and(new Spec(null)->names)->toBe(['default']);
});

it('wraps a single string in a list', function (): void {
    expect(new Spec('v1')->names)->toBe(['v1']);
});

it('preserves a list as-is', function (): void {
    expect(new Spec(['v1', 'v2'])->names)->toBe(['v1', 'v2']);
});
