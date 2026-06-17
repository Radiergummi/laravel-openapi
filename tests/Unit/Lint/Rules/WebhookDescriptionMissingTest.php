<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\WebhookDescriptionMissing;
use Radiergummi\OpenApi\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @throws LogicException
 */
function makeWebhookForDescription(string $name, ?string $description): WebhookNode
{
    $raw = new OA\Post([
        'operationId' => 'webhook.test',
        'responses' => [new OA\Response(['response' => '200', '_context' => new Context()])],
        '_context' => new Context(),
    ]);

    $operationNode = OperationNodeFactory::makeOperation(
        pathUri: $name,
        method: HttpMethod::Post,
        operationId: 'webhook.test',
        description: $description,
        responses: [],
        raw: $raw,
        webhook: true,
    );

    return new WebhookNode(
        name: $name,
        description: $description,
        operation: $operationNode,
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new WebhookDescriptionMissing();

    expect($rule->id())
        ->toBe('webhook.description-missing')
        ->and($rule->severity())->toBe(Severity::Underspecified);
});

it('emits a finding when a webhook has a missing or blank description', function (?string $description): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookForDescription('orderCreated', $description);

    $findings = iterator_to_array(
        $rule->checkWebhook($webhook, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('webhook.description-missing')
        ->and($findings[0]->severity)->toBe(Severity::Underspecified)
        ->and($findings[0]->message)->toContain('orderCreated')
        ->and($findings[0]->location->jsonPointer)->toBe('#/webhooks/orderCreated');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace only' => ['   '],
]);

it('emits no findings when a webhook has a description', function (): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookForDescription(
        'orderCreated',
        'Fired when a new order is created in the system.',
    );

    $findings = iterator_to_array(
        $rule->checkWebhook($webhook, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
