<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\InfoMetadataIncomplete;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;

uses()->group('openapi', 'lint');

function makeInfoMetadataIncompleteContext(
    mixed $contact = null,
    mixed $license = null,
): LintContext {
    $ctx = new Context();

    $infoProps = [
        'title' => 'Test API',
        'version' => '1.0.0',
        '_context' => $ctx,
        'description' => 'A test API.',
    ];

    $info = new OA\Info($infoProps);

    if ($contact !== null) {
        $info->contact = $contact;
    }

    if ($license !== null) {
        $info->license = $license;
    }

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => $info,
        '_context' => $ctx,
    ]);

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

it('has the correct rule id and level', function (): void {
    $rule = new InfoMetadataIncomplete();

    expect($rule->id())->toBe('info.metadata-incomplete')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when both contact and license are absent', function (): void {
    $rule = new InfoMetadataIncomplete();
    // Neither contact nor license set — both remain Generator::UNDEFINED
    $context = makeInfoMetadataIncompleteContext();

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('info.metadata-incomplete')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('contact')
        ->and($findings[0]->message)->toContain('license');
});

it('emits a finding when only contact is absent', function (): void {
    $rule = new InfoMetadataIncomplete();
    $ctx = new Context();
    $license = new OA\License(['name' => 'MIT', '_context' => $ctx]);
    $context = makeInfoMetadataIncompleteContext(license: $license);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('contact');
});

it('emits a finding when only license is absent', function (): void {
    $rule = new InfoMetadataIncomplete();
    $ctx = new Context();
    $contact = new OA\Contact(['name' => 'Support', '_context' => $ctx]);
    $context = makeInfoMetadataIncompleteContext(contact: $contact);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('license');
});

it('emits no finding when both contact and license are present', function (): void {
    $rule = new InfoMetadataIncomplete();
    $ctx = new Context();
    $contact = new OA\Contact(['name' => 'Support', '_context' => $ctx]);
    $license = new OA\License(['name' => 'MIT', '_context' => $ctx]);
    $context = makeInfoMetadataIncompleteContext(contact: $contact, license: $license);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when info is UNDEFINED', function (): void {
    $rule = new InfoMetadataIncomplete();

    $spec = new OA\OpenApi(['openapi' => '3.1.0']);
    // info remains Generator::UNDEFINED — nothing to check
    $context = new LintContext(
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

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});
