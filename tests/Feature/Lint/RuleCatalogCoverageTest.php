<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\RuleRegistry;

it('gives every registered rule a non-empty description and a valid severity', function (): void {
    /** @var RuleRegistry $registry */
    $registry = app(RuleRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    $seenIds = [];

    foreach ($registry->all() as $rule) {
        expect($rule)->toBeInstanceOf(Rule::class);
        expect($rule->severity())->toBeInstanceOf(Severity::class);
        expect(trim($rule->description()))->not->toBe('');
        expect($seenIds)->not->toContain($rule->id());

        $seenIds[] = $rule->id();
    }
})->group('openapi', 'lint');
