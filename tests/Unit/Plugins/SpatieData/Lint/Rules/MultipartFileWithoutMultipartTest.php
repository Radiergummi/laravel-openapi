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
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\SpatieData\FilePropertyChecker;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\MultipartFileWithoutMultipart;
use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithFileUploadData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithFileUploadDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\FileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\FileUploadFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NestedFileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NestedFileUploadFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NoFileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NoFileUploadFixtureData;

uses()->group('openapi', 'lint', 'plugin:spatie-data');

/**
 * Build a stub FilePropertyChecker that returns a fixed answer for hasFileProperties().
 * The map keys are Data-class FQCNs; the value is what hasFileProperties() should return.
 *
 * @param array<class-string, bool> $map
 */
function makeSchemaBuilderStub(array $map): FilePropertyChecker
{
    $stub = Mockery::mock(FilePropertyChecker::class);

    $stub->allows('hasFileProperties')->andReturnUsing(
        static fn(string $class): bool => $map[$class] ?? false,
    );

    return $stub;
}

function makeDirectScannerForMultipart(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

function makeMultipartOperationNode(
    ActionDescriptor $descriptor,
    ?RequestBodyNode $requestBody,
): OperationNode {
    return new OperationNode(
        pathUri: $descriptor->route->uri(),
        method: 'POST',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: $requestBody,
        responses: [],
        security: [],
        tags: [],
        descriptor: $descriptor,
        raw: new OA\Post(['_context' => new Context()]),
        webhook: false,
    );
}

function makeContextForMultipart(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new MultipartFileWithoutMultipart(makeSchemaBuilderStub([]), makeDirectScannerForMultipart());

    expect($rule->id())->toBe('multipart.file-without-multipart')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a file-upload Data class is used without multipart content type', function (): void {
    $route = new Route(['POST'], '/upload', [FileUploadFixtureController::class, 'upload']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(FileUploadFixtureController::class),
        method: new ReflectionMethod(FileUploadFixtureController::class, 'upload'),
        summary: null,
        description: null,
    );

    $requestBody = new RequestBodyNode(
        contentTypes: ['application/json'],
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: null,
        raw: null,
    );

    $operation = makeMultipartOperationNode($descriptor, $requestBody);
    $context = makeContextForMultipart();

    $findings = iterator_to_array(
        new MultipartFileWithoutMultipart(
            makeSchemaBuilderStub([FileUploadFixtureData::class => true]),
            makeDirectScannerForMultipart(),
        )->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('multipart.file-without-multipart')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('FileUploadFixtureController')
        ->and($findings[0]->message)->toContain('upload')
        ->and($findings[0]->message)->toContain('multipart/form-data');
});

it('emits no finding when a file-upload Data class is used with multipart content type', function (): void {
    $route = new Route(['POST'], '/upload', [FileUploadFixtureController::class, 'upload']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(FileUploadFixtureController::class),
        method: new ReflectionMethod(FileUploadFixtureController::class, 'upload'),
        summary: null,
        description: null,
    );

    $requestBody = new RequestBodyNode(
        contentTypes: ['multipart/form-data'],
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: null,
        raw: null,
    );

    $operation = makeMultipartOperationNode($descriptor, $requestBody);
    $context = makeContextForMultipart();

    $findings = iterator_to_array(
        new MultipartFileWithoutMultipart(
            makeSchemaBuilderStub([FileUploadFixtureData::class => true]),
            makeDirectScannerForMultipart(),
        )->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when the Data class has no UploadedFile property', function (): void {
    $route = new Route(['POST'], '/store', [NoFileUploadFixtureController::class, 'store']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(NoFileUploadFixtureController::class),
        method: new ReflectionMethod(NoFileUploadFixtureController::class, 'store'),
        summary: null,
        description: null,
    );

    $requestBody = new RequestBodyNode(
        contentTypes: ['application/json'],
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: null,
        raw: null,
    );

    $operation = makeMultipartOperationNode($descriptor, $requestBody);
    $context = makeContextForMultipart();

    $findings = iterator_to_array(
        new MultipartFileWithoutMultipart(
            makeSchemaBuilderStub([NoFileUploadFixtureData::class => false]),
            makeDirectScannerForMultipart(),
        )->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when the operation has no request body', function (): void {
    $route = new Route(['POST'], '/upload', [FileUploadFixtureController::class, 'upload']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(FileUploadFixtureController::class),
        method: new ReflectionMethod(FileUploadFixtureController::class, 'upload'),
        summary: null,
        description: null,
    );

    $operation = makeMultipartOperationNode($descriptor, null);
    $context = makeContextForMultipart();

    $findings = iterator_to_array(
        new MultipartFileWithoutMultipart(
            makeSchemaBuilderStub([FileUploadFixtureData::class => true]),
            makeDirectScannerForMultipart(),
        )->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('multipart.file-without-multipart');
});

it('emits a finding when an UploadedFile property is inside a nested Data class', function (): void {
    // Reproduces the false negative: the old code only reflected the DIRECT
    // properties of the top-level Data class, so a nested Data class containing
    // an UploadedFile was not detected.
    $route = new Route(['POST'], '/upload', [NestedFileUploadFixtureController::class, 'upload']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(NestedFileUploadFixtureController::class),
        method: new ReflectionMethod(NestedFileUploadFixtureController::class, 'upload'),
        summary: null,
        description: null,
    );

    $requestBody = new RequestBodyNode(
        contentTypes: ['application/json'],
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: null,
        raw: null,
    );

    $operation = makeMultipartOperationNode($descriptor, $requestBody);
    $context = makeContextForMultipart();

    $findings = iterator_to_array(
        new MultipartFileWithoutMultipart(
            makeSchemaBuilderStub([NestedFileUploadFixtureData::class => true]),
            makeDirectScannerForMultipart(),
        )->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('multipart.file-without-multipart')
        ->and($findings[0]->message)->toContain('NestedFileUploadFixtureController');
});

it('emits a finding when a file-upload Data class is injected through a Domain Action', function (): void {
    $route = new Route(['POST'], '/upload', [ActionWithFileUploadDataController::class, 'upload']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(ActionWithFileUploadDataController::class),
        method: new ReflectionMethod(ActionWithFileUploadDataController::class, 'upload'),
        summary: null,
        description: null,
    );

    $requestBody = new RequestBodyNode(
        contentTypes: ['application/json'],
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: null,
        raw: null,
    );

    $operation = makeMultipartOperationNode($descriptor, $requestBody);
    $context = makeContextForMultipart();

    // Scanner descends into ActionWithFileUploadData's constructor to find FileUploadFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithFileUploadData::class]);
    $findings = iterator_to_array(
        new MultipartFileWithoutMultipart(
            makeSchemaBuilderStub([FileUploadFixtureData::class => true]),
            $scanner,
        )->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('multipart.file-without-multipart')
        ->and($findings[0]->message)->toContain('ActionWithFileUploadDataController');
});
