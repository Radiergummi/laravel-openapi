<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\SchemaRawKeywordUnsupported;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\RawSchemaController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function rawKeywordRule(): SchemaRawKeywordUnsupported
{
    return new SchemaRawKeywordUnsupported(new PayloadParameterScanner(indirectionClasses: []));
}

function rawKeywordFindings(string $method): array
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(RawSchemaController::class, $method, '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext();

    return iterator_to_array(rawKeywordRule()->checkOperation($operation, $context));
}

it('reports its id and level 1', function (): void {
    $rule = rawKeywordRule();

    expect($rule->id())->toBe('schema.raw-keyword-unsupported')
        ->and($rule->level())->toBe(1);
});

it('flags a #[RawSchema] carrying an unsupported keyword', function (): void {
    $findings = rawKeywordFindings('unsupported');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.raw-keyword-unsupported')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('if');
});

it('stays silent for a #[RawSchema] with only supported keywords', function (): void {
    expect(rawKeywordFindings('data'))->toBe([]);
});

it('stays silent when the payload has no #[RawSchema]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(RawSchemaController::class, 'noPayload', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');

    expect(iterator_to_array(rawKeywordRule()->checkOperation($operation, OperationNodeFactory::emptyContext())))
        ->toBe([]);
});

it('emits no findings without a descriptor', function (): void {
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/test');

    expect(iterator_to_array(rawKeywordRule()->checkOperation($operation, OperationNodeFactory::emptyContext())))
        ->toBe([]);
});
