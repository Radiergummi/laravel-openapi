<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithWrongScopeData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithWrongScopeDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\WrongScopeFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Spatie\LaravelData\Data;

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
    $descriptor = ActionDescriptorFactory::forControllerMethod(WrongScopeFixtureController::class, $method, '/fixture');

    return OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');
}

it('reports its id and level', function (): void {
    $rule = new FieldAttributeWrongScope(makeDirectOnlyScanner());

    expect($rule->id())->toBe('field.attribute-wrong-scope')
        ->and($rule->level())->toBe(1);
});

it('flags RequestField on a route parameter', function (): void {
    $findings = iterator_to_array(
        new FieldAttributeWrongScope(makeDirectOnlyScanner())->checkOperation(
            makeWrongScopeOperation('requestFieldOnRouteParam'),
            OperationNodeFactory::emptyContext(payloadClasses: [Data::class]),
        ),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.attribute-wrong-scope')
        ->and($findings[0]->message)->toContain('#[RequestField]')
        ->and($findings[0]->fixHint)->toBe('Use #[PathParam] for a URI parameter.');
});

it('flags PathParam on a Data-class property', function (): void {
    $findings = iterator_to_array(
        new FieldAttributeWrongScope(makeDirectOnlyScanner())->checkOperation(
            makeWrongScopeOperation('pathParamOnDataProperty'),
            OperationNodeFactory::emptyContext(payloadClasses: [Data::class]),
        ),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('#[PathParam]')
        ->and($findings[0]->fixHint)->toBe('Use #[RequestField] for a request-body field.');
});

it('does not flag correctly-scoped attributes', function (): void {
    $findings = iterator_to_array(
        new FieldAttributeWrongScope(makeDirectOnlyScanner())->checkOperation(
            makeWrongScopeOperation('correct'),
            OperationNodeFactory::emptyContext(payloadClasses: [Data::class]),
        ),
    );

    expect($findings)->toBe([]);
});

it('flags PathParam on a Data-class property injected through a Domain Action', function (): void {
    // WrongScopeFixtureData (carried by ActionWithWrongScopeData's constructor) has
    // a misplaced #[PathParam] on its $misplaced property. The scanner must descend
    // into the Action constructor to reach it.
    $descriptor = ActionDescriptorFactory::forControllerMethod(ActionWithWrongScopeDataController::class, 'create', '/fixture', ['POST']);

    $operation = OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');

    // Scanner configured with Action::class as an indirection base so it descends
    // into ActionWithWrongScopeData's constructor and finds WrongScopeFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithWrongScopeData::class]);
    $findings = iterator_to_array(
        new FieldAttributeWrongScope($scanner)->checkOperation(
            $operation,
            OperationNodeFactory::emptyContext(payloadClasses: [Data::class]),
        ),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('#[PathParam]')
        ->and($findings[0]->message)->toContain('WrongScopeFixtureData')
        ->and($findings[0]->fixHint)->toBe('Use #[RequestField] for a request-body field.');
});
