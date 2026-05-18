<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Spatie\LaravelData\Data;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithWrongScopeData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithWrongScopeDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\WrongScopeFixtureController;

uses()->group('openapi', 'lint', 'plugin:spatie-data');

/**
 * A scanner with no indirection classes — only direct method parameters are
 * considered. Sufficient for the existing fixtures which pass Data directly.
 */
function makeDirectOnlyScanner(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

function makeWrongScopeOperation(string $method): OperationNode
{
    $reflection = new ReflectionMethod(WrongScopeFixtureController::class, $method);
    $route      = new Route(['GET'], '/fixture', [WrongScopeFixtureController::class, $method]);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
    );

    return new OperationNode(
        pathUri: '/api/v0/test',
        method: 'POST',
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
        raw: new OA\Post(['_context' => new Context()]),
        webhook: false,
    );
}

function makeWrongScopeContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
        payloadClasses: [Data::class],
    );
}

it('reports its id and level', function (): void {
    $rule = new FieldAttributeWrongScope(makeDirectOnlyScanner());

    expect($rule->id())->toBe('field.attribute-wrong-scope')
        ->and($rule->level())->toBe(1);
});

it('flags RequestField on a route parameter', function (): void {
    $findings = iterator_to_array((new FieldAttributeWrongScope(makeDirectOnlyScanner()))->checkOperation(
        makeWrongScopeOperation('requestFieldOnRouteParam'),
        makeWrongScopeContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.attribute-wrong-scope')
        ->and($findings[0]->message)->toContain('#[RequestField]')
        ->and($findings[0]->fixHint)->toBe('Use #[PathParam] for a URI parameter.');
});

it('flags PathParam on a Data-class property', function (): void {
    $findings = iterator_to_array((new FieldAttributeWrongScope(makeDirectOnlyScanner()))->checkOperation(
        makeWrongScopeOperation('pathParamOnDataProperty'),
        makeWrongScopeContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('#[PathParam]')
        ->and($findings[0]->fixHint)->toBe('Use #[RequestField] for a request-body field.');
});

it('does not flag correctly-scoped attributes', function (): void {
    $findings = iterator_to_array((new FieldAttributeWrongScope(makeDirectOnlyScanner()))->checkOperation(
        makeWrongScopeOperation('correct'),
        makeWrongScopeContext(),
    ));

    expect($findings)->toBe([]);
});

it('flags PathParam on a Data-class property injected through a Domain Action', function (): void {
    // WrongScopeFixtureData (carried by ActionWithWrongScopeData's constructor) has
    // a misplaced #[PathParam] on its $misplaced property. The scanner must descend
    // into the Action constructor to reach it.
    $reflection = new ReflectionMethod(ActionWithWrongScopeDataController::class, 'create');
    $route      = new Route(['POST'], '/fixture', [ActionWithWrongScopeDataController::class, 'create']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
    );

    $operation = new OperationNode(
        pathUri: '/api/v0/test',
        method: 'POST',
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
        raw: new OA\Post(['_context' => new Context()]),
        webhook: false,
    );

    // Scanner configured with Action::class as an indirection base so it descends
    // into ActionWithWrongScopeData's constructor and finds WrongScopeFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithWrongScopeData::class]);
    $findings = iterator_to_array(
        (new FieldAttributeWrongScope($scanner))->checkOperation($operation, makeWrongScopeContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('#[PathParam]')
        ->and($findings[0]->message)->toContain('WrongScopeFixtureData')
        ->and($findings[0]->fixHint)->toBe('Use #[RequestField] for a request-body field.');
});
