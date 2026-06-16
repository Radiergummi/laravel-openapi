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

it(
    'does not match a class-scope directive by file path alone when no source-class context is present',
    function (): void {
        // Class-scope suppression requires CONTEXT_SOURCE_CLASS on the finding; file-path matching
        // alone is not sufficient and would incorrectly suppress all classes in the same file.
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

        expect($directive->suppresses($finding))->toBeFalse();
    },
);

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

it('suppresses an operation-level finding when the source class matches the directive target', function (): void {
    $directive = new SuppressionDirective(
        ruleId: 'operation.id-missing',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: '/app/Http/Controllers/UserController.php',
        line: 1,
        targetClass: 'App\\Http\\Controllers\\UserController',
    );

    $finding = new Finding(
        ruleId: 'operation.id-missing',
        level: 1,
        message: 'GET /users has no operationId',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'App\\Http\\Controllers\\UserController',
        ],
    );

    expect($directive->suppresses($finding))->toBeTrue();
});

it('does not suppress an operation-level finding from a different controller in the same file', function (): void {
    // Two controllers in one file: IgnoreLint on ControllerA must not silence ControllerB's findings.
    $directive = new SuppressionDirective(
        ruleId: 'operation.id-missing',
        reason: null,
        scope: SuppressionScope::ClassScope,
        file: '/app/Http/Controllers/SameFile.php',
        line: 1,
        targetClass: 'App\\Http\\Controllers\\ControllerA',
    );

    $findingFromControllerB = new Finding(
        ruleId: 'operation.id-missing',
        level: 1,
        message: 'POST /orders has no operationId',
        location: new FindingLocation(file: '/app/Http/Controllers/SameFile.php'),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'App\\Http\\Controllers\\ControllerB',
        ],
    );

    expect($directive->suppresses($findingFromControllerB))->toBeFalse();
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
