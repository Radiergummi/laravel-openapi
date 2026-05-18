<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\WebhookNameDuplicate;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeWebhookNodeForDuplicateTest(string $name): WebhookNode
{
    $ctx = new Context();

    $operation = new OA\Post([
        'operationId' => 'webhook.' . $name,
        'responses' => [new OA\Response(['response' => '200', '_context' => $ctx])],
        '_context' => $ctx,
    ]);

    $operationNode = new OperationNode(
        pathUri: $name,
        method: 'POST',
        operationId: 'webhook.' . $name,
        summary: null,
        description: null,
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
        name: $name,
        description: null,
        operation: $operationNode,
        raw: null,
    );
}

function makeContextForWebhookNameDuplicate(): LintContext
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

it('reports its id and level', function (): void {
    $rule = new WebhookNameDuplicate();

    expect($rule->id())->toBe('webhook.name-duplicate')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when webhook names are unique', function (): void {
    $rule = new WebhookNameDuplicate();
    $context = makeContextForWebhookNameDuplicate();

    $webhook1 = makeWebhookNodeForDuplicateTest('stripe.payment_intent.succeeded');
    $webhook2 = makeWebhookNodeForDuplicateTest('stripe.payment_intent.failed');

    // Visit each webhook
    iterator_to_array($rule->checkWebhook($webhook1, $context));
    iterator_to_array($rule->checkWebhook($webhook2, $context));

    // Finalize to get findings
    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toBe([]);
});

it('emits findings when webhook names are duplicated', function (): void {
    $rule = new WebhookNameDuplicate();
    $context = makeContextForWebhookNameDuplicate();

    $webhook1 = makeWebhookNodeForDuplicateTest('stripe.payment_intent.succeeded');
    $webhook2 = makeWebhookNodeForDuplicateTest('stripe.payment_intent.succeeded');

    // Visit each webhook
    iterator_to_array($rule->checkWebhook($webhook1, $context));
    iterator_to_array($rule->checkWebhook($webhook2, $context));

    // Finalize to get findings
    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('webhook.name-duplicate')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('stripe.payment_intent.succeeded')
        ->and($findings[0]->message)->toContain('2 times')
        ->and($findings[1]->ruleId)->toBe('webhook.name-duplicate')
        ->and($findings[1]->message)->toContain('stripe.payment_intent.succeeded');
});

it('emits no findings when there are no webhooks visited', function (): void {
    $rule = new WebhookNameDuplicate();
    $context = makeContextForWebhookNameDuplicate();

    // No checkWebhook() calls — finalize directly
    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toBe([]);
});
