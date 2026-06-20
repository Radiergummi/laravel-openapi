<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\SchemaNullableViaDeprecatedKeyword;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeFieldForNullableTest(string $name, bool $setNullableOnRaw): FieldNode
{
    $rawProps = [
        'property' => $name,
        'type' => 'string',
        '_context' => new Context(),
    ];

    if ($setNullableOnRaw) {
        $rawProps['nullable'] = true;
    }

    return OperationNodeFactory::makeField(
        name: $name,
        nullable: $setNullableOnRaw,
        raw: new OA\Property($rawProps),
    );
}

it('reports its id and level', function (): void {
    $rule = new SchemaNullableViaDeprecatedKeyword();

    expect($rule->id)
        ->toBe('schema.nullable-via-deprecated-keyword')
        ->and($rule->severity)->toBe(Severity::Degraded);
});

it('emits no finding when field raw does not use nullable keyword', function (): void {
    $field = makeFieldForNullableTest('UserName', setNullableOnRaw: false);

    $findings = iterator_to_array(
        new SchemaNullableViaDeprecatedKeyword()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when field raw uses deprecated nullable keyword', function (): void {
    $field = makeFieldForNullableTest('UserName', setNullableOnRaw: true);

    $findings = iterator_to_array(
        new SchemaNullableViaDeprecatedKeyword()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.nullable-via-deprecated-keyword')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->message)->toContain('UserName')
        ->and($findings[0]->message)->toContain('nullable');
});

it('emits no finding when field has no raw annotation', function (): void {
    $field = OperationNodeFactory::makeField(name: 'NoRaw');

    $findings = iterator_to_array(
        new SchemaNullableViaDeprecatedKeyword()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits findings for multiple nullable fields', function (): void {
    $rule = new SchemaNullableViaDeprecatedKeyword();
    $context = OperationNodeFactory::emptyContext();

    $findings = [
        ...iterator_to_array($rule->checkField(makeFieldForNullableTest('Name', setNullableOnRaw: true), $context)),
        ...iterator_to_array($rule->checkField(makeFieldForNullableTest('Age', setNullableOnRaw: true), $context)),
    ];

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('Name')
        ->and($findings[1]->message)->toContain('Age');
});
