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
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\InfoMetadataIncomplete;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function infoMetadataIncompleteFindings(bool $withContact, bool $withLicense, bool $withInfo = true): array
{
    $ctx = new Context();

    if (!$withInfo) {
        // info remains Generator::UNDEFINED — nothing for the rule to inspect
        $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $ctx]);
    } else {
        $info = new OA\Info(['title' => 'Test API', 'version' => '1.0.0', 'description' => 'A test API.', '_context' => $ctx]);

        if ($withContact) {
            $info->contact = new OA\Contact(['name' => 'Support', '_context' => $ctx]);
        }

        if ($withLicense) {
            $info->license = new OA\License(['name' => 'MIT', '_context' => $ctx]);
        }

        $spec = new OA\OpenApi(['openapi' => '3.1.0', 'info' => $info, '_context' => $ctx]);
    }

    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    return iterator_to_array(new InfoMetadataIncomplete()->checkApi($api, $lintCtx));
}

it('has the correct rule id and level', function (): void {
    $rule = new InfoMetadataIncomplete();

    expect($rule->id())->toBe('info.metadata-incomplete')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when both contact and license are absent', function (): void {
    $findings = infoMetadataIncompleteFindings(withContact: false, withLicense: false);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('info.metadata-incomplete')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('contact')
        ->and($findings[0]->message)->toContain('license');
});

it('emits a finding mentioning the missing key', function (bool $withContact, bool $withLicense, string $expectedKeyword): void {
    $findings = infoMetadataIncompleteFindings($withContact, $withLicense);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain($expectedKeyword);
})->with([
    'only contact absent' => [false, true, 'contact'],
    'only license absent' => [true, false, 'license'],
]);

it('emits no finding', function (bool $withContact, bool $withLicense, bool $withInfo): void {
    expect(infoMetadataIncompleteFindings($withContact, $withLicense, $withInfo))->toBe([]);
})->with([
    'both contact and license present' => [true, true, true],
    'info is UNDEFINED' => [false, false, false],
]);
