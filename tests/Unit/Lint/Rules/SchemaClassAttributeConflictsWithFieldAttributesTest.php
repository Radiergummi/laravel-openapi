<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\SchemaClassAttributeConflictsWithFieldAttributes;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\RawSchemaController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function classAttributeConflictRule(): SchemaClassAttributeConflictsWithFieldAttributes
{
    return new SchemaClassAttributeConflictsWithFieldAttributes(
        new PayloadParameterScanner(indirectionClasses: []),
    );
}

function classAttributeConflictFindings(string $method): array
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(RawSchemaController::class, $method, '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext();

    return iterator_to_array(classAttributeConflictRule()->checkOperation($operation, $context));
}

it('reports its id and level 3', function (): void {
    $rule = classAttributeConflictRule();

    expect($rule->id())
        ->toBe('schema.class-attribute-conflicts-with-field-attributes')
        ->and($rule->severity())->toBe(Severity::Inconsistent);
});

it('flags a class carrying both #[RawSchema] and a field attribute', function (): void {
    $findings = classAttributeConflictFindings('withFieldAttribute');

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.class-attribute-conflicts-with-field-attributes')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)
        ->toContain('replaces the inferred body')
        ->toContain('have no effect')
        ->toContain('$annotated')
        ->toContain('#[RequestField]');
});

it('flags a JsonResource carrying both #[RawSchema] and class-level #[ResourceField]', function (): void {
    $findings = classAttributeConflictFindings('resourceWithFieldAttribute');

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.class-attribute-conflicts-with-field-attributes')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)
        ->toContain('replaces the inferred body')
        ->toContain('have no effect')
        ->toContain('#[ResourceField]')
        ->toContain('id')
        ->toContain('owner');
});

it('stays silent for class-level #[ResourceField] without a #[RawSchema]', function (): void {
    expect(classAttributeConflictFindings('resourceFieldOnly'))->toBe([]);
});

it('stays silent for a #[RawSchema] without field attributes', function (): void {
    expect(classAttributeConflictFindings('data'))->toBe([]);
});

it('stays silent for field attributes without a class-level #[RawSchema]', function (): void {
    expect(classAttributeConflictFindings('fieldAttributeOnly'))->toBe([]);
});

it('stays silent when the payload has no #[RawSchema]', function (): void {
    expect(classAttributeConflictFindings('noPayload'))->toBe([]);
});

it('emits no findings without a descriptor', function (): void {
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/test');

    expect(iterator_to_array(
        classAttributeConflictRule()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    ))->toBe([]);
});
