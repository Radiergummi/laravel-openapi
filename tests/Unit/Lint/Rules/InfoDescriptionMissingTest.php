<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\InfoDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;

uses()->group('openapi', 'lint');

function makeInfoDescriptionMissingContext(?string $infoDescription): LintContext
{
    $ctx = new Context();

    $info = new OA\Info([
        'title' => 'Test API',
        'version' => '1.0.0',
        '_context' => $ctx,
    ]);

    if ($infoDescription !== null) {
        $info->description = $infoDescription;
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
    $rule = new InfoDescriptionMissing();

    expect($rule->id())->toBe('info.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when info.description is UNDEFINED', function (): void {
    $rule = new InfoDescriptionMissing();
    // Pass null so the Info annotation keeps Generator::UNDEFINED on description
    $context = makeInfoDescriptionMissingContext(infoDescription: null);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('info.description-missing')
        ->and($findings[0]->level)->toBe(2);
});

it('emits a finding when info.description is an empty string', function (): void {
    $rule = new InfoDescriptionMissing();
    $context = makeInfoDescriptionMissingContext(infoDescription: '');

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when info.description is whitespace only', function (): void {
    $rule = new InfoDescriptionMissing();
    $context = makeInfoDescriptionMissingContext(infoDescription: '   ');

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when info.description is set', function (): void {
    $rule = new InfoDescriptionMissing();
    $context = makeInfoDescriptionMissingContext(infoDescription: 'The Matchory supplier discovery API.');

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});
