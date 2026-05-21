<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\DeprecatedNoReplacement;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeDeprecatedNoReplacementOperation(
    bool $deprecated,
    ?string $description = null,
): OperationNode {
    return new OperationNode(
        pathUri: '/deprecated',
        method: 'GET',
        operationId: 'test.deprecated',
        summary: null,
        description: $description,
        deprecated: $deprecated,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeContextForDeprecatedNoReplacement(): LintContext
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

it('has the correct rule id and level', function (): void {
    $rule = new DeprecatedNoReplacement();

    expect($rule->id())->toBe('deprecated.no-replacement')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when deprecated operation has no description', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(deprecated: true);
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.no-replacement')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('replacement');
});

it('emits a finding when deprecated operation description does not mention replacement', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(
        deprecated: true,
        description: 'This endpoint is old and should not be relied upon.',
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1);
});

it('emits no findings when description mentions "use"', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(
        deprecated: true,
        description: 'Deprecated. Use GET /v2/resource instead.',
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when description mentions "replaced by"', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(
        deprecated: true,
        description: 'This endpoint has been replaced by the new v2 endpoint.',
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when description mentions "replacement"', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(
        deprecated: true,
        description: 'A replacement endpoint is available at /v2/resource.',
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when description mentions "sunset"', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(
        deprecated: true,
        description: 'This endpoint will sunset on 2025-12-31.',
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('matches replacement keywords case-insensitively', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(
        deprecated: true,
        description: 'REPLACED BY the new endpoint.',
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings for non-deprecated operations', function (): void {
    $rule = new DeprecatedNoReplacement();
    $operation = makeDeprecatedNoReplacementOperation(deprecated: false);
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

// region Bug 8: x-replacement OAS extension satisfies the requirement

it('emits no findings when deprecated operation has a non-empty x-replacement extension (Bug 8)', function (): void {
    $rule = new DeprecatedNoReplacement();

    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-replacement' => 'GET /v2/resource'];

    $operation = new OperationNode(
        pathUri: '/deprecated',
        method: 'GET',
        operationId: 'test.deprecated',
        summary: null,
        description: null,
        deprecated: true,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: $raw,
        webhook: false,
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('still emits a finding when x-replacement extension is an empty string (Bug 8)', function (): void {
    $rule = new DeprecatedNoReplacement();

    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-replacement' => ''];

    $operation = new OperationNode(
        pathUri: '/deprecated',
        method: 'GET',
        operationId: 'test.deprecated',
        summary: null,
        description: null,
        deprecated: true,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: $raw,
        webhook: false,
    );
    $context = makeContextForDeprecatedNoReplacement();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1);
});

// endregion
