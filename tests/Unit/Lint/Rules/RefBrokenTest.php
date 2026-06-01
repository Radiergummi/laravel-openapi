<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\RefBroken;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function refBrokenFindings(OA\OpenApi $spec): array
{
    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    return iterator_to_array(new RefBroken()->checkApi($api, $context));
}

/**
 * Spec with a wrapper schema that uses a $ref + optional component schemas.
 *
 * @param list<string> $schemas
 */
function specWithRef(string $ref, array $schemas): OA\OpenApi
{
    $context = new Context();

    $oaSchemas = array_map(
        static fn(string $name) => new OA\Schema(['schema' => $name, 'type' => 'object', '_context' => $context]),
        $schemas,
    );

    $oaSchemas[] = new OA\Schema([
        'schema' => 'Wrapper',
        'type' => 'object',
        'properties' => [new OA\Property(['property' => 'related', 'ref' => $ref, '_context' => $context])],
        '_context' => $context,
    ]);

    return new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $context]),
        'components' => new OA\Components(['schemas' => $oaSchemas, '_context' => $context]),
    ]);
}

it('reports its id and level', function (): void {
    $rule = new RefBroken();

    expect($rule->id())->toBe('ref.broken')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when a ref points to a non-existent schema', function (): void {
    $findings = refBrokenFindings(specWithRef(ref: '#/components/schemas/NonExistent', schemas: ['User']));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('ref.broken')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('NonExistent')
        ->and($findings[0]->context['ref'])->toBe('#/components/schemas/NonExistent');
});

it('emits a finding when a ref points to a non-existent response', function (): void {
    $context = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $context]),
        'paths' => [new OA\PathItem([
            'path' => '/test',
            'get' => new OA\Get([
                'operationId' => 'test.op',
                'responses' => [new OA\Response(['ref' => '#/components/responses/NotFoundResponse', '_context' => $context])],
                '_context' => $context,
            ]),
            '_context' => $context,
        ])],
    ]);

    $findings = refBrokenFindings($spec);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context['ref'])->toBe('#/components/responses/NotFoundResponse');
});

it('emits no finding', function (OA\OpenApi $spec): void {
    expect(refBrokenFindings($spec))->toBe([]);
})->with(function () {
    return [
        'all refs resolve to existing components' => fn() => specWithRef(ref: '#/components/schemas/User', schemas: ['User']),
        'no refs' => fn() => new OA\OpenApi([
            'openapi' => '3.1.0',
            'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => new Context()]),
        ]),
        'external refs' => fn() => new OA\OpenApi([
            'openapi' => '3.1.0',
            'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => new Context()]),
            'components' => new OA\Components([
                'schemas' => [new OA\Schema([
                    'schema' => 'Wrapper',
                    'ref' => 'https://example.com/schemas/External.json',
                    '_context' => new Context(),
                ])],
                '_context' => new Context(),
            ]),
        ]),
        'spec has no components and no refs' => fn() => new OA\OpenApi([
            'openapi' => '3.1.0',
            'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => new Context()]),
            'paths' => [new OA\PathItem([
                'path' => '/simple',
                'get' => new OA\Get([
                    'operationId' => 'simple.get',
                    'responses' => [new OA\Response(['response' => '200', '_context' => new Context()])],
                    '_context' => new Context(),
                ]),
                '_context' => new Context(),
            ])],
        ]),
    ];
});
