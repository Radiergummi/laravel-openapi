<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaNullableViaDeprecatedKeyword;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeFieldForNullableTest(
    string $name,
    bool $nullable = false,
    bool $setNullableOnRaw = false,
): FieldNode {
    $ctx = new Context();

    $rawProps = [
        'property' => $name,
        'type' => 'string',
        '_context' => $ctx,
    ];

    if ($setNullableOnRaw) {
        $rawProps['nullable'] = true;
    }

    $raw = new OA\Property($rawProps);

    return new FieldNode(
        name: $name,
        type: 'string',
        required: false,
        nullable: $nullable,
        description: null,
        format: null,
        example: null,
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: $raw,
    );
}

function makeContextForNullableTest(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new SchemaNullableViaDeprecatedKeyword();

    expect($rule->id())
        ->toBe('schema.nullable-via-deprecated-keyword')
        ->and($rule->level())
        ->toBe(1);
});

it('emits no finding when field raw does not use nullable keyword', function (): void {
    $field = makeFieldForNullableTest('UserName', nullable: false, setNullableOnRaw: false);
    $context = makeContextForNullableTest();

    $findings = iterator_to_array(
        (new SchemaNullableViaDeprecatedKeyword())->checkField($field, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when field raw uses deprecated nullable keyword', function (): void {
    $field = makeFieldForNullableTest('UserName', nullable: true, setNullableOnRaw: true);
    $context = makeContextForNullableTest();

    $findings = iterator_to_array(
        (new SchemaNullableViaDeprecatedKeyword())->checkField($field, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('schema.nullable-via-deprecated-keyword')
        ->and($findings[0]->level)
        ->toBe(1)
        ->and($findings[0]->message)
        ->toContain('UserName')
        ->and($findings[0]->message)
        ->toContain('nullable');
});

it('emits no finding when field has no raw annotation', function (): void {
    $field = new FieldNode(
        name: 'NoRaw',
        type: 'string',
        required: false,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );
    $context = makeContextForNullableTest();

    $findings = iterator_to_array(
        (new SchemaNullableViaDeprecatedKeyword())->checkField($field, $context),
    );

    expect($findings)->toBe([]);
});

it('emits findings for multiple nullable fields', function (): void {
    $rule = new SchemaNullableViaDeprecatedKeyword();
    $context = makeContextForNullableTest();

    $field1 = makeFieldForNullableTest('Name', nullable: true, setNullableOnRaw: true);
    $field2 = makeFieldForNullableTest('Age', nullable: true, setNullableOnRaw: true);

    $findings1 = iterator_to_array($rule->checkField($field1, $context));
    $findings2 = iterator_to_array($rule->checkField($field2, $context));

    $findings = [...$findings1, ...$findings2];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('Name')
        ->and($findings[1]->message)->toContain('Age');
});
