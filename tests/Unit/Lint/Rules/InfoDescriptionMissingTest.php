<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\InfoDescriptionMissing;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeInfoContext(?string $infoDescription): LintContext
{
    $context = new Context();

    $info = new OA\Info([
        'title' => 'Test API',
        'version' => '1.0.0',
        '_context' => $context,
    ]);

    if ($infoDescription !== null) {
        $info->description = $infoDescription;
    }

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => $info,
        '_context' => $context,
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

    expect($rule->id)->toBe('info.description-missing')
        ->and($rule->severity)->toBe(Severity::Underspecified);
});

it('emits a finding when info.description is missing or blank', function (?string $description): void {
    $rule = new InfoDescriptionMissing();
    $context = makeInfoContext($description);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('info.description-missing')
        ->and($findings[0]->severity)->toBe(Severity::Underspecified);
})->with([
    // `null` => the Info annotation keeps swagger-php's `Generator::UNDEFINED` sentinel on `description`,
    // which `InfoDescriptionMissing` treats the same as a missing description.
    'null (Generator::UNDEFINED)' => [null],
    'empty string'                => [''],
    'whitespace only'             => ['   '],
]);

it('emits no findings when info.description is set', function (): void {
    $rule = new InfoDescriptionMissing();
    $context = makeInfoContext('The Matchory supplier discovery API.');

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});
