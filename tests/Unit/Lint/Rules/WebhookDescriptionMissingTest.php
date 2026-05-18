<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\WebhookDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeWebhookNodeForDescriptionMissing(
    string $webhookName,
    ?string $description = null,
): WebhookNode {
    $ctx = new Context();

    $operation = new OA\Post([
        'operationId' => 'webhook.test',
        'responses' => [new OA\Response(['response' => '200', '_context' => $ctx])],
        '_context' => $ctx,
    ]);

    $operationNode = new OperationNode(
        pathUri: $webhookName,
        method: 'POST',
        operationId: 'webhook.test',
        summary: null,
        description: $description,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: $operation,
        webhook: true,
    );

    return new WebhookNode(
        name: $webhookName,
        description: $description,
        operation: $operationNode,
        raw: null,
    );
}

function makeContextForWebhookDescriptionMissing(): LintContext
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
    $rule = new WebhookDescriptionMissing();

    expect($rule->id())->toBe('webhook.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a webhook has no description', function (): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookNodeForDescriptionMissing('orderCreated', description: null);
    $context = makeContextForWebhookDescriptionMissing();

    $findings = iterator_to_array($rule->checkWebhook($webhook, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('webhook.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('orderCreated')
        ->and($findings[0]->location->jsonPointer)->toBe('#/webhooks/orderCreated');
});

it('emits a finding when a webhook has an empty description', function (): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookNodeForDescriptionMissing('orderCreated', description: '');
    $context = makeContextForWebhookDescriptionMissing();

    $findings = iterator_to_array($rule->checkWebhook($webhook, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when a webhook has a whitespace-only description', function (): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookNodeForDescriptionMissing('orderCreated', description: '   ');
    $context = makeContextForWebhookDescriptionMissing();

    $findings = iterator_to_array($rule->checkWebhook($webhook, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a webhook has a description', function (): void {
    $rule = new WebhookDescriptionMissing();
    $webhook = makeWebhookNodeForDescriptionMissing(
        'orderCreated',
        description: 'Fired when a new order is created in the system.',
    );
    $context = makeContextForWebhookDescriptionMissing();

    $findings = iterator_to_array($rule->checkWebhook($webhook, $context));

    expect($findings)->toBe([]);
});
