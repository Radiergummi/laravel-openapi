<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\PathTrailingSlashInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PathTrailingSlashInconsistent();

    expect($rule->id())
        ->toBe('path.trailing-slash-inconsistent')
        ->and($rule->level())
        ->toBe(3);
});

it('emits no finding when all paths lack trailing slashes', function (): void {
    $api = makeTrailingSlashApiNode(['/users', '/posts', '/comments']);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array(
        (new PathTrailingSlashInconsistent())->checkApi($api, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when all paths have trailing slashes', function (): void {
    $api = makeTrailingSlashApiNode(['/users/', '/posts/', '/comments/']);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array(
        (new PathTrailingSlashInconsistent())->checkApi($api, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when paths are inconsistent', function (): void {
    $api = makeTrailingSlashApiNode(['/users', '/posts/']);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array(
        (new PathTrailingSlashInconsistent())->checkApi($api, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('path.trailing-slash-inconsistent')
        ->and($findings[0]->level)
        ->toBe(3)
        ->and($findings[0]->message)
        ->toContain('/posts/')
        ->and($findings[0]->message)
        ->toContain('/users');
});

it('skips the root path when checking consistency', function (): void {
    // Only non-root paths matter: /users has no trailing slash, / is skipped
    $api = makeTrailingSlashApiNode(['/', '/users']);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array(
        (new PathTrailingSlashInconsistent())->checkApi($api, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when spec has no paths', function (): void {
    $api = makeTrailingSlashApiNode([]);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array(
        (new PathTrailingSlashInconsistent())->checkApi($api, $context),
    );

    expect($findings)->toBe([]);
});

it('emits exactly one finding even with many inconsistent paths', function (): void {
    $api = makeTrailingSlashApiNode(['/a', '/b/', '/c', '/d/']);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array(
        (new PathTrailingSlashInconsistent())->checkApi($api, $context),
    );

    expect($findings)->toHaveCount(1);
});

/**
 * Build an ApiNode with operations for the given path URIs.
 *
 * @param list<string> $pathUris
 */
function makeTrailingSlashApiNode(array $pathUris): ApiNode
{
    $ctx = new Context();

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $operations = [];

    foreach ($pathUris as $uri) {
        $operations[] = new OperationNode(
            pathUri: $uri,
            method: 'GET',
            operationId: 'op.' . md5($uri),
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
            raw: new OA\Get(['operationId' => 'op.' . md5($uri), '_context' => $ctx]),
        );
    }

    return new ApiNode(
        operations: $operations,
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
}
