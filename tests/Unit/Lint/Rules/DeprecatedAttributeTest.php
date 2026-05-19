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
use Radiergummi\OpenApi\Core\Lint\Rules\DeprecatedAttribute;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DeprecatedAttrClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DeprecatedAttrController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

function makeDeprecatedAttrOperationNode(ActionDescriptor $descriptor): OperationNode
{
    return new OperationNode(
        pathUri: '/fixture',
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: $descriptor,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeContextForDeprecatedAttr(): LintContext
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
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');

    expect($rule->id())->toBe('deprecated.attribute')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when a method uses a deprecated OpenAPI attribute', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withDeprecatedAttribute', '/fixture');
    $operation = makeDeprecatedAttrOperationNode($descriptor);
    $context = makeContextForDeprecatedAttr();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.attribute')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('deprecated');
});

it('emits no findings when a method uses a non-deprecated OpenAPI attribute', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withNonDeprecatedAttribute', '/fixture');
    $operation = makeDeprecatedAttrOperationNode($descriptor);
    $context = makeContextForDeprecatedAttr();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when a method has no attributes', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withoutAttributes', '/fixture');
    $operation = makeDeprecatedAttrOperationNode($descriptor);
    $context = makeContextForDeprecatedAttr();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('uses class-level message wording when the deprecated attribute is on the controller class', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');

    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrClassController::class, 'index', '/fixture');
    $operation = makeDeprecatedAttrOperationNode($descriptor);
    $context = makeContextForDeprecatedAttr();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('class DeprecatedAttrClassController')
        ->and($findings[0]->message)->not->toContain('::index()');
});

it('uses method-level message wording when the deprecated attribute is on the method', function (): void {
    $rule = new DeprecatedAttribute('Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\');
    $descriptor = ActionDescriptorFactory::forControllerMethod(DeprecatedAttrController::class, 'withDeprecatedAttribute', '/fixture');
    $operation = makeDeprecatedAttrOperationNode($descriptor);
    $context = makeContextForDeprecatedAttr();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('DeprecatedAttrController::withDeprecatedAttribute()');
});
