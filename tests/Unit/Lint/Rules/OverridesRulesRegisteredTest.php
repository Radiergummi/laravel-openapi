<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Lint\Rules\OverridesUnknownField;
use Radiergummi\OpenApi\Lint\Rules\OverridesUnused;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;

uses()->group('openapi', 'lint');

it('registers both override lint rules as pre-build rules', function (): void {
    config()->set('openapi.overrides', []);

    $classes = array_map(
        static fn(PreBuildRule $rule): string => $rule::class,
        app(RuleRegistry::class)->preBuildRules(),
    );

    expect($classes)->toContain(OverridesUnknownField::class)
        ->and($classes)->toContain(OverridesUnused::class);
});
