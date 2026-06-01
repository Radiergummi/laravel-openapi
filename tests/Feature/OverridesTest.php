<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\Operation;

uses()->group('openapi');

// region Fixture controller with an attribute-derived summary we can override
class OverridesFixtureController
{
    #[Operation(summary: 'Summary from attribute')]
    public function index(): array
    {
        return [];
    }

    public function legacy(): array
    {
        return [];
    }
}

beforeEach(function (): void {
    Route::get('/api/overrides/users', [OverridesFixtureController::class, 'index'])
        ->name('overrides.users');
    Route::get('/api/v1/legacy/thing', [OverridesFixtureController::class, 'legacy']);
});
// endregion

it('applies a config override keyed by route name and beats the attribute value', function (): void {
    config()->set('openapi.overrides', [
        'overrides.users' => [
            'summary'    => 'Summary from config override',
            'deprecated' => true,
            'tags'       => ['Identity'],
        ],
    ]);

    $spec = generateSpec();
    $op = $spec['paths']['/api/overrides/users']['get'];

    expect($op['summary'])->toBe('Summary from config override')
        ->and($op['deprecated'])->toBeTrue()
        ->and($op['tags'])->toBe(['Identity']);
});

it('applies a URI-glob override and emits x-* extensions', function (): void {
    config()->set('openapi.overrides', [
        'api/v1/legacy/*' => ['x-internal' => true],
    ]);

    $spec = generateSpec();
    $op = $spec['paths']['/api/v1/legacy/thing']['get'];

    expect($op['x-internal'])->toBeTrue();
});

it('leaves operations untouched when overrides is empty', function (): void {
    config()->set('openapi.overrides', []);

    $spec = generateSpec();
    $op = $spec['paths']['/api/overrides/users']['get'];

    expect($op['summary'])->toBe('Summary from attribute');
});
