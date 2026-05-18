<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;

uses()->group('openapi', 'lint');

it('filters rules by level (only rules at or below the cap are kept)', function (): void {
    $r0 = new class () implements Rule {
        public function id(): string
        {
            return 'a';
        }

        public function level(): int
        {
            return 0;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $r2 = new class () implements Rule {
        public function id(): string
        {
            return 'b';
        }

        public function level(): int
        {
            return 2;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $registry = new RuleRegistry([$r0, $r2]);

    expect($registry->forLevel(0))->toHaveCount(1)
        ->and($registry->forLevel(0)[0]->id())->toBe('a')
        ->and($registry->forLevel(2))->toHaveCount(2);
});

it('applies --only and --skip filters', function (): void {
    $r1 = new class () implements Rule {
        public function id(): string
        {
            return 'foo';
        }

        public function level(): int
        {
            return 0;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $r2 = new class () implements Rule {
        public function id(): string
        {
            return 'bar';
        }

        public function level(): int
        {
            return 0;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $registry = new RuleRegistry([$r1, $r2]);

    expect($registry->forLevel(0, only: ['foo']))->toHaveCount(1)
        ->and($registry->forLevel(0, only: ['foo'])[0]->id())->toBe('foo')
        ->and($registry->forLevel(0, skip: ['foo']))->toHaveCount(1)
        ->and($registry->forLevel(0, skip: ['foo'])[0]->id())->toBe('bar');
});

it('returns the known rule ids regardless of filter', function (): void {
    $r = new class () implements Rule {
        public function id(): string
        {
            return 'foo';
        }

        public function level(): int
        {
            return 0;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    expect((new RuleRegistry([$r]))->knownIds())->toBe(['foo']);
});

it('severity override downgrades a rule so forLevel excludes it at the original level', function (): void {
    // Rule is level 0 by default — downgrade it to 2 via override.
    // At level cap 0 the registry should now exclude it.
    $rule = new class () implements Rule {
        public function id(): string
        {
            return 'some.rule';
        }

        public function level(): int
        {
            return 0;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $registry = new RuleRegistry([$rule], severityOverrides: ['some.rule' => 2]);

    expect($registry->forLevel(0))->toHaveCount(0)
        ->and($registry->forLevel(2))->toHaveCount(1);
});

it('severity override upgrades a rule so forLevel includes it below its declared level', function (): void {
    // Rule is level 2 — upgrade it to 0 so it appears under a level-0 cap.
    $rule = new class () implements Rule {
        public function id(): string
        {
            return 'some.rule';
        }

        public function level(): int
        {
            return 2;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $registry = new RuleRegistry([$rule], severityOverrides: ['some.rule' => 0]);

    expect($registry->forLevel(0))->toHaveCount(1);
});

it('severity override changes maxLevel to reflect the remapped level', function (): void {
    $rule = new class () implements Rule {
        public function id(): string
        {
            return 'some.rule';
        }

        public function level(): int
        {
            return 3;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    $registry = new RuleRegistry([$rule], severityOverrides: ['some.rule' => 1]);

    expect($registry->maxLevel())->toBe(1);
});

it('spec.invalid is exempt from severity_overrides', function (): void {
    $rule = new class () implements Rule {
        public function id(): string
        {
            return 'spec.invalid';
        }

        public function level(): int
        {
            return 0;
        }

        public function description(): string
        {
            return 'test rule';
        }

        public function check(LintContext $c): iterable
        {
            return [];
        }
    };

    // Attempt to remap spec.invalid to level 5 — must be ignored.
    $registry = new RuleRegistry([$rule], severityOverrides: ['spec.invalid' => 5]);

    expect($registry->forLevel(0))->toHaveCount(1)
        ->and($registry->effectiveLevelFor('spec.invalid', 0))->toBe(0);
});
