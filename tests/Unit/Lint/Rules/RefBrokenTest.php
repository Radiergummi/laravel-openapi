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
use Radiergummi\OpenApi\Core\Lint\Rules\RefBroken;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeApiNodeForRefBroken(OA\OpenApi $spec): ApiNode
{
    return new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
}

it('reports its id and level', function (): void {
    $rule = new RefBroken();

    expect($rule->id())->toBe('ref.broken')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all refs resolve to existing components', function (): void {
    $spec = makeSpecWithRef(
        ref: '#/components/schemas/User',
        schemas: ['User'],
    );
    $api = makeApiNodeForRefBroken($spec);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array((new RefBroken())->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});

it('emits a finding when a ref points to a non-existent schema', function (): void {
    $spec = makeSpecWithRef(
        ref: '#/components/schemas/NonExistent',
        schemas: ['User'],
    );
    $api = makeApiNodeForRefBroken($spec);
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array((new RefBroken())->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('ref.broken')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('NonExistent')
        ->and($findings[0]->context['ref'])->toBe('#/components/schemas/NonExistent');
});

it('emits no finding when there are no refs', function (): void {
    $ctx = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);
    $api = makeApiNodeForRefBroken($spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array((new RefBroken())->checkApi($api, $lintCtx));

    expect($findings)->toBe([]);
});

it('emits no finding for external refs', function (): void {
    $ctx = new Context();
    $schema = new OA\Schema([
        'schema' => 'Wrapper',
        'ref' => 'https://example.com/schemas/External.json',
        '_context' => $ctx,
    ]);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'components' => new OA\Components([
            'schemas' => [$schema],
            '_context' => $ctx,
        ]),
    ]);
    $api = makeApiNodeForRefBroken($spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array((new RefBroken())->checkApi($api, $lintCtx));

    expect($findings)->toBe([]);
});

it('emits a finding when a ref points to a non-existent response', function (): void {
    $ctx = new Context();
    $responseRef = new OA\Response([
        'ref' => '#/components/responses/NotFoundResponse',
        '_context' => $ctx,
    ]);
    $operation = new OA\Get([
        'operationId' => 'test.op',
        'responses' => [$responseRef],
        '_context' => $ctx,
    ]);
    $path = new OA\PathItem(['path' => '/test', 'get' => $operation, '_context' => $ctx]);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'paths' => [$path],
    ]);
    $api = makeApiNodeForRefBroken($spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array((new RefBroken())->checkApi($api, $lintCtx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context['ref'])->toBe('#/components/responses/NotFoundResponse');
});

it('emits no finding when spec has no components and no refs', function (): void {
    $ctx = new Context();
    $operation = new OA\Get([
        'operationId' => 'simple.get',
        'responses' => [new OA\Response(['response' => '200', '_context' => $ctx])],
        '_context' => $ctx,
    ]);
    $path = new OA\PathItem(['path' => '/simple', 'get' => $operation, '_context' => $ctx]);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'paths' => [$path],
    ]);
    $api = makeApiNodeForRefBroken($spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array((new RefBroken())->checkApi($api, $lintCtx));

    expect($findings)->toBe([]);
});

/**
 * Build a minimal spec with a schema that uses a $ref and optional component schemas.
 *
 * @param list<string> $schemas Names of schemas to register as components
 */
function makeSpecWithRef(string $ref, array $schemas): OA\OpenApi
{
    $ctx = new Context();

    $oaSchemas = [];

    foreach ($schemas as $name) {
        $oaSchemas[] = new OA\Schema([
            'schema' => $name,
            'type' => 'object',
            '_context' => $ctx,
        ]);
    }

    // Create a property that uses a $ref
    $refProperty = new OA\Property([
        'property' => 'related',
        'ref' => $ref,
        '_context' => $ctx,
    ]);

    $wrapperSchema = new OA\Schema([
        'schema' => 'Wrapper',
        'type' => 'object',
        'properties' => [$refProperty],
        '_context' => $ctx,
    ]);

    $oaSchemas[] = $wrapperSchema;

    return new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'components' => new OA\Components([
            'schemas' => $oaSchemas,
            '_context' => $ctx,
        ]),
    ]);
}
