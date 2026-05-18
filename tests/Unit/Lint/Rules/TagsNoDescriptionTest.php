<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\TagsNoDescription;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

/**
 * @param list<array{name: string, description?: string}> $tags
 */
function makeApiNodeForTagsNoDescription(array $tags): ApiNode
{
    $ctx = new Context();

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $declaredTags = [];
    $tagDescriptions = [];

    foreach ($tags as $tag) {
        $declaredTags[] = $tag['name'];

        if (isset($tag['description'])) {
            $tagDescriptions[$tag['name']] = $tag['description'];
        }
    }

    return new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: $declaredTags,
        tagDescriptions: $tagDescriptions,
        raw: $spec,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new TagsNoDescription();

    expect($rule->id())->toBe('tags.no-description')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a tag has no description', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Users'],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tags.no-description')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('"Users"');
});

it('emits a finding when a tag has an empty description', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Users', 'description' => ''],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"Users"');
});

it('emits a finding when a tag has a whitespace-only description', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Users', 'description' => '   '],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when all tags have descriptions', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Users', 'description' => 'User management endpoints'],
        ['name' => 'Admin', 'description' => 'Admin-only endpoints'],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});

it('emits findings only for tags without descriptions in a mixed set', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Users', 'description' => 'Has description'],
        ['name' => 'Admin'],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"Admin"');
});

it('emits no findings when there are no tags', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});

it('includes the json pointer using the array index in the finding location', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Search'],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('#/tags/0');
});

it('uses the correct index when multiple tags are declared and the second has no description', function (): void {
    $rule = new TagsNoDescription();
    $api = makeApiNodeForTagsNoDescription([
        ['name' => 'Users', 'description' => 'User management'],
        ['name' => 'Admin'],
    ]);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('#/tags/1');
});
