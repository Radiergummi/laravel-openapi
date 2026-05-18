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
use Radiergummi\OpenApi\Core\Lint\Rules\ComponentOrphaned;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeSpecForComponentOrphaned(array $schemas = [], array $refsUsed = []): OA\OpenApi
{
    $ctx = new Context();

    $schemaAnnotations = [];

    foreach ($schemas as $name) {
        $schemaAnnotations[] = new OA\Schema([
            'schema' => $name,
            'type' => 'object',
            '_context' => $ctx,
        ]);
    }

    $paths = [];

    if ($refsUsed !== []) {
        $mediaTypes = [];

        foreach ($refsUsed as $ref) {
            $mediaTypes[] = new OA\MediaType([
                'mediaType' => 'application/json',
                'schema' => new OA\Schema([
                    'ref' => $ref,
                    '_context' => $ctx,
                ]),
                '_context' => $ctx,
            ]);
        }

        $response = new OA\Response([
            'response' => '200',
            'content' => $mediaTypes,
            '_context' => $ctx,
        ]);
        $operation = new OA\Get([
            'operationId' => 'test.index',
            'responses' => [$response],
            '_context' => $ctx,
        ]);
        $paths[] = new OA\PathItem([
            'path' => '/test',
            'get' => $operation,
            '_context' => $ctx,
        ]);
    }

    $props = [
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ];

    if ($schemaAnnotations !== []) {
        $props['components'] = new OA\Components([
            'schemas' => $schemaAnnotations,
            '_context' => $ctx,
        ]);
    }

    if ($paths !== []) {
        $props['paths'] = $paths;
    }

    return new OA\OpenApi($props);
}

/**
 * Build a referencedComponents index from the given refs.
 *
 * @param list<string> $refs e.g. ['#/components/schemas/User']
 *
 * @return array<string, true>
 */
function buildReferencedComponentsIndex(array $refs): array
{
    $index = [];

    foreach ($refs as $ref) {
        // Strip '#/components/' prefix and use the remainder as key
        $key = str_replace('#/components/', '', $ref);
        $index[$key] = true;
    }

    return $index;
}

it('has the correct rule id and level', function (): void {
    $rule = new ComponentOrphaned();

    expect($rule->id())->toBe('component.orphaned')
        ->and($rule->level())->toBe(3);
});

it('emits a finding for an unreferenced schema', function (): void {
    $rule = new ComponentOrphaned();
    $spec = makeSpecForComponentOrphaned(
        schemas: ['User'],
        refsUsed: [],
    );

    $api = new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: buildReferencedComponentsIndex([]),
        registeredScopes: [],
        knownRuleIds: [],
    );

    $ctx = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('component.orphaned')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('User')
        ->and($findings[0]->context['component'])->toBe('User');
});

it('emits no findings when all schemas are referenced', function (): void {
    $rule = new ComponentOrphaned();
    $spec = makeSpecForComponentOrphaned(
        schemas: ['User'],
        refsUsed: ['#/components/schemas/User'],
    );

    $api = new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: buildReferencedComponentsIndex(['#/components/schemas/User']),
        registeredScopes: [],
        knownRuleIds: [],
    );

    $ctx = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});

it('emits findings for multiple orphaned schemas', function (): void {
    $rule = new ComponentOrphaned();
    $spec = makeSpecForComponentOrphaned(
        schemas: ['User', 'Post', 'Comment'],
        refsUsed: ['#/components/schemas/User'],
    );

    $api = new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: buildReferencedComponentsIndex(['#/components/schemas/User']),
        registeredScopes: [],
        knownRuleIds: [],
    );

    $ctx = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(2);

    $orphanedNames = array_map(
        fn($f) => $f->context['component'],
        $findings,
    );

    expect($orphanedNames)->toContain('Post')
        ->and($orphanedNames)->toContain('Comment');
});

it('emits no findings when there are no components', function (): void {
    $rule = new ComponentOrphaned();
    $ctx = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $api = new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: [],
    );

    $lintCtx = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $lintCtx));

    expect($findings)->toBe([]);
});

it('includes the json pointer in the finding location', function (): void {
    $rule = new ComponentOrphaned();
    $spec = makeSpecForComponentOrphaned(
        schemas: ['OrphanedSchema'],
        refsUsed: [],
    );

    $api = new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: [],
    );

    $ctx = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/OrphanedSchema');
});
