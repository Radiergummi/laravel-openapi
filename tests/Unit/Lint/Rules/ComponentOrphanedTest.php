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

/**
 * @param list<string> $schemas  schema names to register under components
 * @param list<string> $refsUsed full $ref strings used by an operation response;
 *                               also seeds TreeIndex->referencedComponents
 */
function componentOrphanedFindings(array $schemas, array $refsUsed): array
{
    $ctx = new Context();

    $props = [
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ];

    if ($schemas !== []) {
        $props['components'] = new OA\Components([
            'schemas' => array_map(
                static fn(string $name) => new OA\Schema(['schema' => $name, 'type' => 'object', '_context' => $ctx]),
                $schemas,
            ),
            '_context' => $ctx,
        ]);
    }

    if ($refsUsed !== []) {
        $props['paths'] = [new OA\PathItem([
            'path' => '/test',
            'get' => new OA\Get([
                'operationId' => 'test.index',
                'responses' => [new OA\Response([
                    'response' => '200',
                    'content' => array_map(
                        static fn(string $ref) => new OA\MediaType([
                            'mediaType' => 'application/json',
                            'schema' => new OA\Schema(['ref' => $ref, '_context' => $ctx]),
                            '_context' => $ctx,
                        ]),
                        $refsUsed,
                    ),
                    '_context' => $ctx,
                ])],
                '_context' => $ctx,
            ]),
            '_context' => $ctx,
        ])];
    }

    $spec = new OA\OpenApi($props);

    $referencedComponents = [];

    foreach ($refsUsed as $ref) {
        $referencedComponents[str_replace('#/components/', '', $ref)] = true;
    }

    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: $referencedComponents,
        registeredScopes: [],
        knownRuleIds: [],
    );
    $lintCtx = new LintContext(api: $api, index: $index, rawSpec: $spec, actionDescriptors: [], suppressions: []);

    return iterator_to_array(new ComponentOrphaned()->checkApi($api, $lintCtx));
}

it('has the correct rule id and level', function (): void {
    $rule = new ComponentOrphaned();

    expect($rule->id())->toBe('component.orphaned')
        ->and($rule->level())->toBe(3);
});

it('emits a finding for an unreferenced schema', function (): void {
    $findings = componentOrphanedFindings(schemas: ['User'], refsUsed: []);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('component.orphaned')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('User')
        ->and($findings[0]->context['component'])->toBe('User');
});

it('emits findings for multiple orphaned schemas', function (): void {
    $findings = componentOrphanedFindings(
        schemas: ['User', 'Post', 'Comment'],
        refsUsed: ['#/components/schemas/User'],
    );

    $orphanedNames = array_map(static fn($f) => $f->context['component'], $findings);

    expect($findings)->toHaveCount(2)
        ->and($orphanedNames)->toContain('Post')
        ->and($orphanedNames)->toContain('Comment');
});

it('includes the json pointer in the finding location', function (): void {
    $findings = componentOrphanedFindings(schemas: ['OrphanedSchema'], refsUsed: []);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/OrphanedSchema');
});

it('emits no findings', function (array $schemas, array $refsUsed): void {
    expect(componentOrphanedFindings($schemas, $refsUsed))->toBe([]);
})->with([
    'all schemas referenced' => [['User'], ['#/components/schemas/User']],
    'no components' => [[], []],
]);
