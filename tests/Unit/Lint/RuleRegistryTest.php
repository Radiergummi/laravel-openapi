<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\RuleRegistry;

/** Builds a minimal {@see Rule} stub with the given id and severity. */
$rule = static fn(string $id, Severity $severity): Rule => new readonly class ($id, $severity) implements Rule {
    public function __construct(private string $ruleId, private Severity $ruleSeverity) {}

    public function id(): string
    {
        return $this->ruleId;
    }

    public function severity(): Severity
    {
        return $this->ruleSeverity;
    }

    public function description(): string
    {
        return 'stub';
    }
};

// The registry performs no duplicate-id detection; these tests pin the current observed
// behaviour so a future change to that contract is a deliberate, visible one.

it('keeps every rule when two share an id', function () use ($rule): void {
    $registry = new RuleRegistry([
        $rule('fake.duplicate', Severity::Underspecified),
        $rule('fake.duplicate', Severity::Improvable),
    ]);

    expect($registry->all())->toHaveCount(2)
        ->and($registry->knownIds())->toBe(['fake.duplicate', 'fake.duplicate']);
});

it('returns both same-id rules from forLevel', function () use ($rule): void {
    $registry = new RuleRegistry([
        $rule('fake.duplicate', Severity::Underspecified),
        $rule('fake.duplicate', Severity::Improvable),
    ]);

    expect($registry->forLevel(4))->toHaveCount(2)
        // The stricter of the two still passes a tighter level threshold.
        ->and($registry->forLevel(2))->toHaveCount(1);
});

it('applies a severity override to every rule sharing the overridden id', function () use ($rule): void {
    $registry = new RuleRegistry(
        [
            $rule('fake.duplicate', Severity::Underspecified),
            $rule('fake.duplicate', Severity::Improvable),
        ],
        ['fake.duplicate' => 0],
    );

    // The override is keyed by id, so both instances collapse to the remapped severity.
    expect($registry->effectiveLevelFor('fake.duplicate', Severity::Underspecified))->toBe(Severity::Broken)
        ->and($registry->maxLevel())->toBe(0);
});

it('floors a negative severity override to the most severe case', function () use ($rule): void {
    $registry = new RuleRegistry([$rule('fake.rule', Severity::Underspecified)], ['fake.rule' => -1]);

    expect($registry->effectiveLevelFor('fake.rule', Severity::Underspecified))->toBe(Severity::Broken);
});

it('clamps an out-of-range high override to the least severe case', function () use ($rule): void {
    // The severity space is closed, so a stray high int can no longer suppress a finding by
    // sitting above every threshold; it surfaces at the least-severe level instead.
    $registry = new RuleRegistry([$rule('fake.rule', Severity::Underspecified)], ['fake.rule' => 999]);

    expect($registry->effectiveLevelFor('fake.rule', Severity::Underspecified))->toBe(Severity::Improvable);
});

it('keeps spec.invalid exempt from severity overrides', function () use ($rule): void {
    $registry = new RuleRegistry(
        [$rule(RuleRegistry::EXEMPT_RULE_ID, Severity::Broken)],
        [RuleRegistry::EXEMPT_RULE_ID => 4],
    );

    expect($registry->effectiveLevelFor(RuleRegistry::EXEMPT_RULE_ID, Severity::Broken))
        ->toBe(Severity::Broken);
});
