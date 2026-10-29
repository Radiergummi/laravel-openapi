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
        expect($rule->severity)->toBeInstanceOf(Severity::class);
        expect(trim($rule->description))->not->toBe('');
        expect($seenIds)->not->toContain($rule->id);

        $seenIds[] = $rule->id;
    }
})->group('openapi', 'lint');

it('lists every registered rule in the hand-maintained docs/linting.md catalog', function (): void {
    /** @var RuleRegistry $registry */
    $registry = app(RuleRegistry::class);
    $docs = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/linting.md');

    preg_match('/<!-- BEGIN: lint-rule-catalog -->(.*)<!-- END: lint-rule-catalog -->/s', $docs, $catalog);
    preg_match_all('/^\| `([^`]+)` \|/m', $catalog[1] ?? '', $documented);

    $missing = array_values(array_diff(
        array_map(static fn(Rule $rule): string => $rule->id, $registry->all()),
        $documented[1],
    ));

    expect($missing)->toBe([]);
})->group('openapi', 'lint');
