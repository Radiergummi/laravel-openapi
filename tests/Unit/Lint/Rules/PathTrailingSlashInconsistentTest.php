<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\PathTrailingSlashInconsistent;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param list<string> $pathUris
 */
function trailingSlashContext(array $pathUris): LintContext
{
    $operations = [];

    foreach ($pathUris as $uri) {
        $operations[] = OperationNodeFactory::makeOperation(
            pathUri: $uri,
            operationId: 'op.' . md5($uri),
            responses: [],
        );
    }

    return OperationNodeFactory::emptyContext(operations: $operations);
}

it('reports its id and level', function (): void {
    $rule = new PathTrailingSlashInconsistent();

    expect($rule->id)
        ->toBe('path.trailing-slash-inconsistent')
        ->and($rule->severity)
        ->toBe(Severity::Inconsistent);
});

it('emits no finding when paths are consistent', function (array $pathUris): void {
    $context = trailingSlashContext($pathUris);

    $findings = iterator_to_array(
        new PathTrailingSlashInconsistent()->checkApi($context->api, $context),
    );

    expect($findings)->toBe([]);
})->with([
    'all without trailing slash' => [['/users', '/posts', '/comments']],
    'all with trailing slash' => [['/users/', '/posts/', '/comments/']],
    'root path is skipped' => [['/', '/users']],
    'no paths at all' => [[]],
]);

it('emits a finding when paths are inconsistent', function (): void {
    $context = trailingSlashContext(['/users', '/posts/']);

    $findings = iterator_to_array(
        new PathTrailingSlashInconsistent()->checkApi($context->api, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('path.trailing-slash-inconsistent')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('/posts/')
        ->and($findings[0]->message)->toContain('/users');
});

it('emits exactly one finding even with many inconsistent paths', function (): void {
    $context = trailingSlashContext(['/a', '/b/', '/c', '/d/']);

    $findings = iterator_to_array(
        new PathTrailingSlashInconsistent()->checkApi($context->api, $context),
    );

    expect($findings)->toHaveCount(1);
});
