<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\WebhookNameDuplicate;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new WebhookNameDuplicate();

    expect($rule->id)->toBe('webhook.name-duplicate')
        ->and($rule->severity)->toBe(Severity::Broken);
});

it('emits no finding when webhook names are unique', function (): void {
    $rule = new WebhookNameDuplicate();
    $context = OperationNodeFactory::emptyContext();

    iterator_to_array($rule->checkWebhook(OperationNodeFactory::makeWebhook('stripe.payment_intent.succeeded'), $context));
    iterator_to_array($rule->checkWebhook(OperationNodeFactory::makeWebhook('stripe.payment_intent.failed'), $context));

    expect(iterator_to_array($rule->finalize($context)))->toBe([]);
});

it('emits findings when webhook names are duplicated', function (): void {
    $rule = new WebhookNameDuplicate();
    $context = OperationNodeFactory::emptyContext();

    iterator_to_array($rule->checkWebhook(OperationNodeFactory::makeWebhook('stripe.payment_intent.succeeded'), $context));
    iterator_to_array($rule->checkWebhook(OperationNodeFactory::makeWebhook('stripe.payment_intent.succeeded'), $context));

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('webhook.name-duplicate')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('stripe.payment_intent.succeeded')
        ->and($findings[0]->message)->toContain('2 times')
        ->and($findings[1]->ruleId)->toBe('webhook.name-duplicate')
        ->and($findings[1]->message)->toContain('stripe.payment_intent.succeeded');
});

it('emits no findings when there are no webhooks visited', function (): void {
    $rule = new WebhookNameDuplicate();
    $context = OperationNodeFactory::emptyContext();

    expect(iterator_to_array($rule->finalize($context)))->toBe([]);
});
