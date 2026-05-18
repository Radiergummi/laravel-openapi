<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;

uses()->group('openapi', 'lint');

it('emits rule.unknown when an unknown Rule object cannot be introspected', function (): void {
    $collector = new ArrayFindingsCollector();
    $mapper = new ValidationRulesToSchema(findings: $collector);

    $customRule = new class () implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $result = $mapper->process(['color' => [$customRule]], sourceClass: 'SomeData');

    $findings = collect($collector->all())
        ->filter(static fn($f) => $f->ruleId === 'rule.unknown')
        ->values();

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->context['rule_class'])->toBe($customRule::class)
        ->and($findings->first()->context['property'])->toBe('color')
        ->and($findings->first()->context['source_class'])->toBe('SomeData');
});
