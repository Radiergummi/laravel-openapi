<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Lint\Rules\StreamingNoContentType;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\StreamingFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function streamingFindings(string $controllerMethod, string $routeName, ?string $contentType): array
{
    $route = new Route(['GET'], '/fixture', [StreamingFixtureController::class, $controllerMethod]);
    $route->name($routeName);
    $descriptor = ActionDescriptorFactory::forRoute($route, StreamingFixtureController::class, $controllerMethod);

    $context = new Context();
    $responseProps = ['response' => '200', '_context' => $context];

    if ($contentType !== null) {
        $responseProps['content'] = [
            new OA\MediaType([
                'mediaType' => $contentType,
                'schema' => new OA\Schema(['type' => 'string', '_context' => $context]),
                '_context' => $context,
            ]),
        ];
    }

    $raw = new OA\Get([
        'operationId' => $routeName,
        'responses' => [new OA\Response($responseProps)],
        '_context' => $context,
    ]);

    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        pathUri: '/fixture',
        operationId: $routeName,
        raw: $raw,
    );

    return iterator_to_array(
        new StreamingNoContentType()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new StreamingNoContentType();

    expect($rule->id())->toBe('streaming.no-content-type')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when streaming endpoint has no text/event-stream content type', function (): void {
    $findings = streamingFindings('stream', 'test.stream', 'application/json');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('streaming.no-content-type')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('stream');
});

it('emits a finding when streaming endpoint response has no content at all', function (): void {
    $findings = streamingFindings('stream', 'test.stream', null);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('streaming.no-content-type');
});

it('emits no findings', function (string $method, string $name, ?string $contentType): void {
    expect(streamingFindings($method, $name, $contentType))->toBe([]);
})->with([
    'streaming endpoint with text/event-stream' => ['stream', 'test.stream', 'text/event-stream'],
    'method not marked as streaming' => ['nonStreaming', 'test.non-streaming', 'application/json'],
    'method has no Operation attribute' => ['noAttribute', 'test.no-attr', 'application/json'],
]);
