<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\SanitizeOperationIdFixer;
use Radiergummi\OpenApi\Lint\Rules\OperationIdInvalidChars;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\InvalidOperationIdFixtureController;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('rewrites an operationId with spaces and illegal characters to the sanitised value', function (): void {
    $result = AttributeFixFixture::run(
        new OperationIdInvalidChars(),
        InvalidOperationIdFixtureController::class,
        'withSpaces',
        extraContext: [SanitizeOperationIdFixer::CONTEXT_OPERATION_ID => 'list_users_'],
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toContain("#[Operation(operationId: 'list_users_')]")
        ->and($result['after'])->not->toContain("'list users!'");
});

it('rewrites a leading-digit operationId to a letter-prefixed form', function (): void {
    $result = AttributeFixFixture::run(
        new OperationIdInvalidChars(),
        InvalidOperationIdFixtureController::class,
        'withLeadingDigits',
        extraContext: [SanitizeOperationIdFixer::CONTEXT_OPERATION_ID => 'users'],
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toContain("#[Operation(operationId: 'users')]");
});

it('yields nothing when the stamped sanitised value is absent (degrade)', function (): void {
    $finding = new Finding(
        ruleId: 'operation.id-invalid-chars',
        severity: (new OperationIdInvalidChars())->severity,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => InvalidOperationIdFixtureController::class,
            Finding::CONTEXT_SOURCE_MEMBER => 'withSpaces',
        ],
    );

    $fixes = iterator_to_array(new SanitizeOperationIdFixer()->fix($finding, new FixContext()));

    expect($fixes)->toBe([]);
});

it('yields nothing when the method carries no #[Operation] attribute (degrade)', function (): void {
    $finding = new Finding(
        ruleId: 'operation.id-invalid-chars',
        severity: (new OperationIdInvalidChars())->severity,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\DuplicateTagFixtureController::class,
            Finding::CONTEXT_SOURCE_MEMBER => 'index',
            SanitizeOperationIdFixer::CONTEXT_OPERATION_ID => 'users',
        ],
    );

    $fixes = iterator_to_array(new SanitizeOperationIdFixer()->fix($finding, new FixContext()));

    expect($fixes)->toBe([]);
});

it('produces an operationId the invalid-chars rule no longer flags', function (): void {
    $sanitised = (new OperationIdInvalidChars())->fixer();

    // The sanitised value the rule stamps must itself match the codegen-safe pattern, so a second
    // lint pass over the fixed source finds nothing (the fix converges in one pass).
    expect(preg_match(OperationIdInvalidChars::PATTERN, 'list_users_'))->toBe(1)
        ->and($sanitised)->toBeInstanceOf(SanitizeOperationIdFixer::class);
});
