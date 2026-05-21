<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\Rules\WebhookDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeWebhookForDescription(string $name, ?string $description): WebhookNode
{
    $raw = new OA\Post([
        'operationId' => 'webhook.test',
        'responses' => [new OA\Response(['response' => '200', '_context' => new Context()])],
        '_context' => new Context(),
    ]);

    $operationNode = OperationNodeFactory::makeOperation(
        pathUri: $name,
        method: 'POST',
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

    expect($rule->id())->toBe('webhook.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a webhook has a missing or blank description', function (?string $description): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookForDescription('orderCreated', $description);

    $findings = iterator_to_array(
        $rule->checkWebhook($webhook, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('webhook.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('orderCreated')
        ->and($findings[0]->location->jsonPointer)->toBe('#/webhooks/orderCreated');
})->with([
    'null'            => [null],
    'empty string'    => [''],
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
