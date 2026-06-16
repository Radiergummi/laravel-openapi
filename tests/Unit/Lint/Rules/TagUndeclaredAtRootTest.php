<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\TagUndeclaredAtRoot;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param list<list<string>> $operationTags
 * @param list<string>       $rootTags
 */
function tagUndeclaredAtRootContext(array $operationTags, array $rootTags = []): LintContext
{
    $operations = [];

    foreach ($operationTags as $index => $tags) {
        $operations[] = OperationNodeFactory::makeOperation(
            pathUri: '/path-' . $index,
            operationId: 'op.' . $index,
            responses: [],
            tags: $tags,
        );
    }

    return OperationNodeFactory::emptyContext(
        declaredTags: $rootTags,
        operations: $operations,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new TagUndeclaredAtRoot();

    expect($rule->id())->toBe('tag.undeclared-at-root')
        ->and($rule->severity())->toBe(Severity::Inconsistent);
});

it('emits a finding when a tag is not declared at root', function (): void {
    $context = tagUndeclaredAtRootContext(operationTags: [['Users']], rootTags: []);

    $findings = iterator_to_array(new TagUndeclaredAtRoot()->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tag.undeclared-at-root')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('"Users"');
});

it('emits no findings when all tags are declared at root', function (): void {
    $context = tagUndeclaredAtRootContext(operationTags: [['Users', 'Admin']], rootTags: ['Users', 'Admin']);

    $findings = iterator_to_array(new TagUndeclaredAtRoot()->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits findings for each undeclared tag per operation', function (): void {
    $context = tagUndeclaredAtRootContext(operationTags: [['Users', 'Search']], rootTags: ['Users']);

    $findings = iterator_to_array(new TagUndeclaredAtRoot()->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"Search"');
});

it('emits findings across multiple operations', function (): void {
    $context = tagUndeclaredAtRootContext(operationTags: [['MissingA'], ['MissingB']], rootTags: []);

    $findings = iterator_to_array(new TagUndeclaredAtRoot()->checkApi($context->api, $context));

    expect($findings)->toHaveCount(2);
});

it('emits no findings when there are no tags or paths', function (array $operationTags): void {
    $context = tagUndeclaredAtRootContext(operationTags: $operationTags, rootTags: []);

    $findings = iterator_to_array(new TagUndeclaredAtRoot()->checkApi($context->api, $context));

    expect($findings)->toBe([]);
})->with([
    'operation has no tags' => [[[]]],
    'no operations at all'  => [[]],
]);
