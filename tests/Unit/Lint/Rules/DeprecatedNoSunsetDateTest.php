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
use Radiergummi\OpenApi\Core\Lint\Rules\DeprecatedNoSunsetDate;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeDeprecatedNoSunsetDateOperation(
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

function makeContextForDeprecatedNoSunsetDate(): LintContext
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
    $rule = new DeprecatedNoSunsetDate();

    expect($rule->id())->toBe('deprecated.no-sunset-date')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when deprecated operation has no description', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(deprecated: true);
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.no-sunset-date')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('sunset');
});

it('emits a finding when deprecated operation description has no date', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(
        deprecated: true,
        description: 'This endpoint is deprecated. Please migrate to the new API.',
    );
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1);
});

it('emits no findings when description contains an ISO date', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(
        deprecated: true,
        description: 'Deprecated. Will be removed on 2025-12-31.',
    );
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when description contains a quarter notation', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(
        deprecated: true,
        description: 'Deprecated. Sunset in Q1 2026.',
    );
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when description contains only a month name (no ISO date or quarter)', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(
        deprecated: true,
        description: 'Deprecated. Will be removed in January 2026.',
    );
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1);
});

it('emits a finding when description contains the word "may" (a common modal verb)', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(
        deprecated: true,
        description: 'This endpoint is deprecated and may be removed in the future.',
    );
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1);
});

it('emits no findings for non-deprecated operations', function (): void {
    $rule = new DeprecatedNoSunsetDate();
    $operation = makeDeprecatedNoSunsetDateOperation(deprecated: false);
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

// ---------------------------------------------------------------------------
// Bug 8: x-sunset OAS extension satisfies the requirement
// ---------------------------------------------------------------------------

it('emits no findings when deprecated operation has a non-empty x-sunset extension (Bug 8)', function (): void {
    $rule = new DeprecatedNoSunsetDate();

    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-sunset' => '2026-12-31'];

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
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('still emits a finding when x-sunset extension is an empty string (Bug 8)', function (): void {
    $rule = new DeprecatedNoSunsetDate();

    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-sunset' => ''];

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
    $context = makeContextForDeprecatedNoSunsetDate();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1);
});
