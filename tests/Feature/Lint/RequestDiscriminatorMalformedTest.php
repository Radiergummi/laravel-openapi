<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\DiscriminatedRequestFixtureController;

uses()->group('openapi', 'lint');

it('emits request.discriminator-malformed for a branch with neither schema nor fields', function (): void {
    Route::post('/oa-139/malformed', [DiscriminatedRequestFixtureController::class, 'malformed'])->name('oa-139.malformed');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $findings = collect($collector->all())
        ->filter(static fn(Finding $f): bool => $f->ruleId === 'request.discriminator-malformed')
        ->values()
        ->all();

    expect($findings)->not->toBeEmpty()
        ->and($findings[0]->message)->toContain('exactly one of schema/fields');
});

it('emits request.discriminator-malformed for a duplicate variant value', function (): void {
    Route::post('/oa-139/duplicate', [DiscriminatedRequestFixtureController::class, 'duplicate'])->name('oa-139.duplicate');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $messages = collect($collector->all())
        ->filter(static fn(Finding $f): bool => $f->ruleId === 'request.discriminator-malformed')
        ->map(static fn(Finding $f): string => $f->message)
        ->all();

    expect($messages)->toContain("duplicate #[RequestVariant] value 'aws'");
});

it('emits request.discriminator-malformed for an unresolvable class-string schema', function (): void {
    Route::post('/oa-139/unresolvable', [DiscriminatedRequestFixtureController::class, 'unresolvable'])->name('oa-139.unresolvable');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $messages = collect($collector->all())
        ->filter(static fn(Finding $f): bool => $f->ruleId === 'request.discriminator-malformed')
        ->map(static fn(Finding $f): string => $f->message)
        ->all();

    expect($messages)->toContain("#[RequestVariant] 'weird' schema 'DateTimeImmutable' is not resolvable to a component");
});

it('emits request.discriminator-malformed when a discriminator is set but no variant is declared', function (): void {
    Route::post('/oa-139/no-variants', [DiscriminatedRequestFixtureController::class, 'noVariants'])->name('oa-139.no-variants');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $messages = collect($collector->all())
        ->filter(static fn(Finding $f): bool => $f->ruleId === 'request.discriminator-malformed')
        ->map(static fn(Finding $f): string => $f->message)
        ->all();

    expect($messages)->toContain('discriminator is set but no #[RequestVariant] is declared');
});

it('emits request.discriminator-malformed when a sanitised branch key collides', function (): void {
    Route::post('/oa-139/colliding', [DiscriminatedRequestFixtureController::class, 'colliding'])->name('oa-139.colliding');

    $collector = new ArrayFindingsCollector();
    $this->app->forgetScopedInstances();
    $this->app->instance(FindingsCollector::class, $collector);

    $this->app->make(OpenApiGenerator::class)
        ->generate($this->app->make(SpecRegistry::class)->default(), 'testing');

    $findings = collect($collector->all())
        ->filter(static fn(Finding $f): bool => $f->ruleId === 'request.discriminator-malformed')
        ->values()
        ->all();

    expect($findings)->not->toBeEmpty();
});
