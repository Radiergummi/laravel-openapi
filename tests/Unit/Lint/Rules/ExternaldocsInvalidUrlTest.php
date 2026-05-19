<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\ExternaldocsInvalidUrl;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvalidExternalDocsController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new ExternaldocsInvalidUrl();

    expect($rule->id())->toBe('externaldocs.invalid-url')
        ->and($rule->level())->toBe(1);
});

it('emits no findings for a valid URL', function (): void {
    $rule = new ExternaldocsInvalidUrl();
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidExternalDocsController::class, 'withValidUrl', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding for an invalid URL', function (): void {
    $rule = new ExternaldocsInvalidUrl();
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidExternalDocsController::class, 'withInvalidUrl', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('externaldocs.invalid-url')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('not-a-url');
});

it('emits a finding for an empty URL', function (): void {
    $rule = new ExternaldocsInvalidUrl();
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidExternalDocsController::class, 'withEmptyUrl', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('externaldocs.invalid-url');
});

it('emits no findings when a method has no ExternalDocs attribute', function (): void {
    $rule = new ExternaldocsInvalidUrl();
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidExternalDocsController::class, 'withoutExternalDocs', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});
