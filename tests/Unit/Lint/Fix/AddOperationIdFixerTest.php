<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\AddOperationIdFixer;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Rules\OperationIdMissing;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\MissingOperationIdFixtureController;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('synthesises a new #[Operation] attribute on a method that has none', function (): void {
    $result = AttributeFixFixture::run(
        new OperationIdMissing(),
        MissingOperationIdFixtureController::class,
        'withoutAttribute',
        extraContext: [AddOperationIdFixer::CONTEXT_OPERATION_ID => 'users.index'],
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toContain(
            "#[\Radiergummi\OpenApi\Attributes\Operation(operationId: 'users.index')]",
        )
        ->and($result['after'])->toContain('public function withoutAttribute(): void {}');
});

it('adds the operationId argument to an existing #[Operation] without duplicating the attribute', function (): void {
    $result = AttributeFixFixture::run(
        new OperationIdMissing(),
        MissingOperationIdFixtureController::class,
        'withAttribute',
        extraContext: [AddOperationIdFixer::CONTEXT_OPERATION_ID => 'users.show'],
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toContain(
            "#[Operation(summary: 'List users', operationId: 'users.show')]",
        )
        // No second attribute was synthesised.
        ->and(substr_count($result['after'], '#[Operation'))->toBe(1)
        ->and($result['after'])->not->toContain('Attributes\Operation(operationId');
});

it('yields nothing when the stamped operationId is absent (degrade)', function (): void {
    $finding = new Finding(
        ruleId: 'operation.id-missing',
        severity: (new OperationIdMissing())->severity,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => MissingOperationIdFixtureController::class,
            Finding::CONTEXT_SOURCE_MEMBER => 'withoutAttribute',
        ],
    );

    $fixes = iterator_to_array(new AddOperationIdFixer()->fix($finding, new FixContext()));

    expect($fixes)->toBe([]);
});

it('yields nothing when the source member is unknown (degrade)', function (): void {
    $finding = new Finding(
        ruleId: 'operation.id-missing',
        severity: (new OperationIdMissing())->severity,
        message: 'fixture',
        context: [AddOperationIdFixer::CONTEXT_OPERATION_ID => 'users.index'],
    );

    $fixes = iterator_to_array(new AddOperationIdFixer()->fix($finding, new FixContext()));

    expect($fixes)->toBe([]);
});

it('emits AddAttribute when no #[Operation] exists and SetAttributeArgument when one does', function (): void {
    $fixer = new AddOperationIdFixer();

    $without = iterator_to_array($fixer->fix(
        operationIdMissingFinding('withoutAttribute', 'users.index'),
        new FixContext(),
    ));
    $with = iterator_to_array($fixer->fix(
        operationIdMissingFinding('withAttribute', 'users.show'),
        new FixContext(),
    ));

    expect($without[0]->operation)->toBeInstanceOf(Radiergummi\OpenApi\Lint\Fix\Ast\AddAttribute::class)
        ->and($with[0]->operation)->toBeInstanceOf(Radiergummi\OpenApi\Lint\Fix\Ast\SetAttributeArgument::class);
});

function operationIdMissingFinding(string $member, string $operationId): Finding
{
    return new Finding(
        ruleId: 'operation.id-missing',
        severity: (new OperationIdMissing())->severity,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => MissingOperationIdFixtureController::class,
            Finding::CONTEXT_SOURCE_MEMBER => $member,
            AddOperationIdFixer::CONTEXT_OPERATION_ID => $operationId,
        ],
    );
}
