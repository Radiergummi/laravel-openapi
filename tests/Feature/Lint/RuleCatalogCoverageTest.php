<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;

it('gives every registered rule a non-empty description and a valid level', function (): void {
    /** @var RuleRegistry $registry */
    $registry = app(RuleRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    $seenIds = [];

    foreach ($registry->all() as $rule) {
        expect($rule)->toBeInstanceOf(Rule::class);
        expect($rule->level())->toBeGreaterThanOrEqual(0);
        expect(trim($rule->description()))->not->toBe('');
        expect($seenIds)->not->toContain($rule->id());

        $seenIds[] = $rule->id();
    }
})->group('openapi', 'lint');
