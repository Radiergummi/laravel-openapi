<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\RuleRegistry;

/** Builds a minimal {@see Rule} stub with the given id and level. */
$rule = static fn(string $id, int $level): Rule => new readonly class ($id, $level) implements Rule {
    public function __construct(private string $ruleId, private int $ruleLevel) {}

    public function id(): string
    {
        return $this->ruleId;
    }

    public function level(): int
    {
        return max(0, $this->ruleLevel);
    }

    public function description(): string
    {
        return 'stub';
    }
};

// The registry performs no duplicate-id detection; these tests pin the current observed
// behaviour so a future change to that contract is a deliberate, visible one.

it('keeps every rule when two share an id', function () use ($rule): void {
    $registry = new RuleRegistry([$rule('fake.duplicate', 2), $rule('fake.duplicate', 4)]);

    expect($registry->all())->toHaveCount(2)
        ->and($registry->knownIds())->toBe(['fake.duplicate', 'fake.duplicate']);
});

it('returns both same-id rules from forLevel', function () use ($rule): void {
    $registry = new RuleRegistry([$rule('fake.duplicate', 2), $rule('fake.duplicate', 4)]);

    expect($registry->forLevel(4))->toHaveCount(2)
        // The stricter of the two still passes a tighter level threshold.
        ->and($registry->forLevel(2))->toHaveCount(1);
});

it('applies a severity override to every rule sharing the overridden id', function () use ($rule): void {
    $registry = new RuleRegistry(
        [$rule('fake.duplicate', 2), $rule('fake.duplicate', 4)],
        ['fake.duplicate' => 0],
    );

    // The override is keyed by id, so both instances collapse to the remapped level.
    expect($registry->effectiveLevelFor('fake.duplicate', 2))->toBe(0)
        ->and($registry->maxLevel())->toBe(0);
});
