<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\UnmappedException;

uses()->group('openapi', 'lint');

it('emits throws.unmapped when @throws references an unmapped exception', function (): void {
    /**
     * @throws \Radiergummi\OpenApi\Tests\Fixtures\Lint\UnmappedException
     */
    $handler = static function (): array {
        throw new UnmappedException('boom');
    };

    Route::get('lint-test/unmapped-throws', $handler)->name('lint-test.unmapped-throws');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $findings = collect($collector->all())
        ->filter(static fn($f) => $f->ruleId === 'throws.unmapped');

    expect($findings->isNotEmpty())->toBeTrue();
});
