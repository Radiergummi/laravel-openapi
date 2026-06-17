<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ExternaldocsInvalidUrl;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvalidExternalDocsController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function externaldocsFindings(string $method): array
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidExternalDocsController::class, $method, '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');

    return iterator_to_array(
        new ExternaldocsInvalidUrl()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new ExternaldocsInvalidUrl();

    expect($rule->id())->toBe('externaldocs.invalid-url')
        ->and($rule->severity())->toBe(Severity::Degraded);
});

it('emits a finding for an invalid URL', function (): void {
    $findings = externaldocsFindings('withInvalidUrl');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('externaldocs.invalid-url')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->message)->toContain('not-a-url');
});

it('emits a finding for an empty URL', function (): void {
    $findings = externaldocsFindings('withEmptyUrl');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('externaldocs.invalid-url');
});

it('emits no findings', function (string $method): void {
    expect(externaldocsFindings($method))->toBe([]);
})->with([
    'valid URL' => 'withValidUrl',
    'no ExternalDocs attribute' => 'withoutExternalDocs',
]);
