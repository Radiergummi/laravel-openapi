<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\StreamingNoContentType;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\StreamingFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

function makeStreamingDescriptor(string $method, string $routeName): ActionDescriptor
{
    $route = new Route(['GET'], '/fixture', [StreamingFixtureController::class, $method]);
    $route->name($routeName);

    return ActionDescriptorFactory::forRoute($route, StreamingFixtureController::class, $method);
}

function makeStreamingRawOperation(string $operationId, ?string $contentType = null): OA\Operation
{
    $ctx = new Context();

    $responseProps = [
        'response' => '200',
        '_context' => $ctx,
    ];

    if ($contentType !== null) {
        $responseProps['content'] = [
            new OA\MediaType([
                'mediaType' => $contentType,
                'schema' => new OA\Schema(['type' => 'string', '_context' => $ctx]),
                '_context' => $ctx,
            ]),
        ];
    }

    $response = new OA\Response($responseProps);

    return new OA\Get([
        'operationId' => $operationId,
        'responses' => [$response],
        '_context' => $ctx,
    ]);
}

function makeStreamingOperationNode(
    ActionDescriptor $descriptor,
    OA\Operation $raw,
): OperationNode {
    return new OperationNode(
        pathUri: '/fixture',
        method: 'GET',
        operationId: $descriptor->route->getName(),
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
        raw: $raw,
        webhook: false,
    );
}

function makeContextForStreaming(): LintContext
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
    $rule = new StreamingNoContentType();

    expect($rule->id())->toBe('streaming.no-content-type')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when streaming endpoint has no text/event-stream content type', function (): void {
    $rule = new StreamingNoContentType();
    $descriptor = makeStreamingDescriptor('stream', 'test.stream');
    $raw = makeStreamingRawOperation('test.stream', 'application/json');
    $operation = makeStreamingOperationNode($descriptor, $raw);
    $context = makeContextForStreaming();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('streaming.no-content-type')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('stream');
});

it('emits no findings when streaming endpoint has text/event-stream content type', function (): void {
    $rule = new StreamingNoContentType();
    $descriptor = makeStreamingDescriptor('stream', 'test.stream');
    $raw = makeStreamingRawOperation('test.stream', 'text/event-stream');
    $operation = makeStreamingOperationNode($descriptor, $raw);
    $context = makeContextForStreaming();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when method is not marked as streaming', function (): void {
    $rule = new StreamingNoContentType();
    $descriptor = makeStreamingDescriptor('nonStreaming', 'test.non-streaming');
    $raw = makeStreamingRawOperation('test.non-streaming', 'application/json');
    $operation = makeStreamingOperationNode($descriptor, $raw);
    $context = makeContextForStreaming();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when method has no Operation attribute', function (): void {
    $rule = new StreamingNoContentType();
    $descriptor = makeStreamingDescriptor('noAttribute', 'test.no-attr');
    $raw = makeStreamingRawOperation('test.no-attr', 'application/json');
    $operation = makeStreamingOperationNode($descriptor, $raw);
    $context = makeContextForStreaming();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when streaming endpoint response has no content at all', function (): void {
    $rule = new StreamingNoContentType();
    $descriptor = makeStreamingDescriptor('stream', 'test.stream');
    $raw = makeStreamingRawOperation('test.stream');
    $operation = makeStreamingOperationNode($descriptor, $raw);
    $context = makeContextForStreaming();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('streaming.no-content-type');
});
