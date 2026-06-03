<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Lint\SuppressionScope;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;

uses()->group('openapi', 'lint');

it('matches a class-scope directive structurally via the source class context', function (): void {
    $directive = new SuppressionDirective(
        ruleId: 'field.name-naming-inconsistent',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: '/app/Requests/Some.php',
        line: 1,
        targetClass: 'App\\Requests\\Some',
    );

    $finding = new Finding(
        ruleId: 'field.name-naming-inconsistent',
        level: 3,
        message: 'snake case',
        location: new FindingLocation(jsonPointer: '#/components/schemas/Some/properties/error_uri'),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'App\\Requests\\Some',
            Finding::CONTEXT_SOURCE_MEMBER => 'error_uri',
        ],
    );

    expect($directive->suppresses($finding))->toBeTrue();
});

it('does not match when the structural class context names a different class', function (): void {
    $directive = new SuppressionDirective(
        ruleId: 'field.name-naming-inconsistent',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: '/app/Requests/Some.php',
        line: 1,
        targetClass: 'App\\Requests\\Some',
    );

    $finding = new Finding(
        ruleId: 'field.name-naming-inconsistent',
        level: 3,
        message: 'snake case',
        location: new FindingLocation(jsonPointer: '#/components/schemas/Other/properties/foo'),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'App\\Requests\\Other',
            Finding::CONTEXT_SOURCE_MEMBER => 'foo',
        ],
    );

    expect($directive->suppresses($finding))->toBeFalse();
});

it('still matches a class-scope directive by file path when no source-class context is present', function (): void {
    $directive = new SuppressionDirective(
        ruleId: 'response.no-error',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: __FILE__,
        line: 1,
        targetClass: 'App\\Controller',
    );

    $finding = new Finding(
        ruleId: 'response.no-error',
        level: 1,
        message: 'no error response',
        location: new FindingLocation(file: __FILE__),
    );

    expect($directive->suppresses($finding))->toBeTrue();
});

it('suppresses a rule.unknown finding via class scope using its source class', function (): void {
    // rule.unknown is emitted by ValidationRulesToSchema with no source file on the finding, so a
    // class-scoped #[IgnoreLint] can only match it through the source-class context. This pins that
    // these schema-derived findings are class-suppressible.
    $collector = new ArrayFindingsCollector();
    $mapper = new ValidationRulesToSchema(findings: $collector);

    $customRule = new class () implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $mapper->process(['color' => [$customRule]], sourceClass: 'App\\Data\\SomeData');

    $unknownFindings = array_filter(
        $collector->all(),
        static fn(Finding $f): bool => $f->ruleId === 'rule.unknown',
    );
    expect($unknownFindings)->toHaveCount(1);

    $directive = new SuppressionDirective(
        ruleId: 'rule.unknown',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: '/app/Data/SomeData.php',
        line: 1,
        targetClass: 'App\\Data\\SomeData',
    );

    foreach ($unknownFindings as $finding) {
        expect($directive->suppresses($finding))->toBeTrue();
    }
});

it('does not match a class-scope directive when the rule ID differs', function (): void {
    $directive = new SuppressionDirective(
        ruleId: 'field.name-naming-inconsistent',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: '/app/Requests/Some.php',
        line: 1,
        targetClass: 'App\\Requests\\Some',
    );

    $finding = new Finding(
        ruleId: 'response.no-error',
        level: 1,
        message: 'no error response',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'App\\Requests\\Some',
        ],
    );

    expect($directive->suppresses($finding))->toBeFalse();
});
