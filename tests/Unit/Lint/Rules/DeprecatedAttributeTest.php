<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\DeprecatedAttribute;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DeprecatedAttrClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DeprecatedAttrController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

const DEPRECATED_ATTR_NAMESPACE = 'Radiergummi\\OpenApi\\Tests\\Fixtures\\Lint\\';

function deprecatedAttributeFindings(string $controller, string $method): array
{
    $descriptor = ActionDescriptorFactory::forControllerMethod($controller, $method, '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');

    return iterator_to_array(
        new DeprecatedAttribute(DEPRECATED_ATTR_NAMESPACE)->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new DeprecatedAttribute(DEPRECATED_ATTR_NAMESPACE);

    expect($rule->id())->toBe('deprecated.attribute')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when a method uses a deprecated OpenAPI attribute', function (): void {
    $findings = deprecatedAttributeFindings(DeprecatedAttrController::class, 'withDeprecatedAttribute');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.attribute')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('deprecated');
});

it('emits no findings', function (string $method): void {
    expect(deprecatedAttributeFindings(DeprecatedAttrController::class, $method))->toBe([]);
})->with([
    'non-deprecated attribute' => 'withNonDeprecatedAttribute',
    'no attributes' => 'withoutAttributes',
]);

it('uses class-level message wording when the deprecated attribute is on the controller class', function (): void {
    $findings = deprecatedAttributeFindings(DeprecatedAttrClassController::class, 'index');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('class DeprecatedAttrClassController')
        ->and($findings[0]->message)->not->toContain('::index()');
});

it('uses method-level message wording when the deprecated attribute is on the method', function (): void {
    $findings = deprecatedAttributeFindings(DeprecatedAttrController::class, 'withDeprecatedAttribute');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('DeprecatedTestAttribute')
        ->and($findings[0]->message)->toContain('DeprecatedAttrController::withDeprecatedAttribute()');
});
