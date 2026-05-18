<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SpecInvalid;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    $this->schemaPath
        = dirname(__DIR__, 5) . '/../resources/openapi/oas-3.1-schema.json';
});

function makeApiNodeForSpec(OA\OpenApi $spec): ApiNode
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

it('emits no findings for a minimal valid OAS 3.1 spec', function (): void {
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info([
            'title' => 'Test',
            'version' => '0.1',
            '_context' => new Context(),
        ]),
        'paths' => [
            new OA\PathItem(['path' => '/test', '_context' => new Context()]),
        ],
    ]);

    $rule = new SpecInvalid($this->schemaPath);
    $api = makeApiNodeForSpec($spec);
    $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toBe([]);
});

it(
    'emits a finding when the spec violates the OAS 3.1 schema',
    function (): void {
        $invalid = new OA\OpenApi(['openapi' => '3.1.0']);

        $rule = new SpecInvalid($this->schemaPath);
        $api = makeApiNodeForSpec($invalid);
        $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $invalid, actionDescriptors: [], suppressions: []);

        $findings = iterator_to_array($rule->checkApi($api, $context));

        expect($findings)
            ->not->toBeEmpty()
            ->and($findings[0]->ruleId)
            ->toBe('spec.invalid')
            ->and($findings[0]->level)
            ->toBe(0);
    },
);

it(
    'filters out false-positive $dynamicRef findings (openapi/info required at nested paths)',
    function (): void {
        // A spec missing 'info' at the root is a legitimate error.
        // But a nested schema object missing 'openapi'/'info' is a false positive
        // from the OAS 3.1 meta-schema's $dynamicRef propagation.
        $invalid = new OA\OpenApi(['openapi' => '3.1.0']);

        $rule = new SpecInvalid($this->schemaPath);
        $api = makeApiNodeForSpec($invalid);
        $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $invalid, actionDescriptors: [], suppressions: []);

        $findings = iterator_to_array($rule->checkApi($api, $context));

        // None of the findings should mention 'openapi' or 'info' as missing
        // at a nested path — those are all false positives that must be filtered.
        $nestedOpenapiFindings = array_filter(
            $findings,
            static fn($f) => str_contains($f->message, 'openapi, info')
                && $f->location->jsonPointer !== '/',
        );

        expect($nestedOpenapiFindings)->toBeEmpty();
    },
);

it(
    'produces interpolated messages without raw placeholders',
    function (): void {
        // A spec missing info triggers a real finding at root level
        $invalid = new OA\OpenApi(['openapi' => '3.1.0']);

        $rule = new SpecInvalid($this->schemaPath);
        $api = makeApiNodeForSpec($invalid);
        $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $invalid, actionDescriptors: [], suppressions: []);

        $findings = iterator_to_array($rule->checkApi($api, $context));

        expect($findings)->not->toBeEmpty();

        foreach ($findings as $finding) {
            // No raw {placeholder} syntax in messages
            expect($finding->message)->not->toMatch('/{[a-z_]+}/i');
            expect($finding->fixHint)->not->toBeNull();
            expect($finding->context)->toHaveKey('keyword');
        }
    },
);

it(
    'deduplicates findings with the same pointer and message',
    function (): void {
        $invalid = new OA\OpenApi(['openapi' => '3.1.0']);

        $rule = new SpecInvalid($this->schemaPath);
        $api = makeApiNodeForSpec($invalid);
        $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $invalid, actionDescriptors: [], suppressions: []);

        $findings = iterator_to_array($rule->checkApi($api, $context));

        $seen = [];
        $duplicates = 0;

        foreach ($findings as $finding) {
            $key = $finding->location->jsonPointer . '|' . $finding->message;

            if (isset($seen[$key])) {
                $duplicates++;
            }

            $seen[$key] = true;
        }

        expect($duplicates)->toBe(0);
    },
);

it(
    'resolves route source location when action descriptors are provided',
    function (): void {
        $spec = new OA\OpenApi([
            'openapi' => '3.1.0',
            'info' => new OA\Info([
                'title' => 'Test',
                'version' => '0.1',
                '_context' => new Context(),
            ]),
            'paths' => [
                new OA\PathItem([
                    'path' => '/test',
                    'get' => new OA\Get([
                        'operationId' => 'test.index',
                        // Intentionally invalid: parameter without required 'name'
                        'parameters' => [new OA\Parameter(['in' => 'query'])],
                        'responses' => [
                            new OA\Response([
                                'response' => 200,
                                'description' => 'OK',
                            ]),
                        ],
                    ]),
                    '_context' => new Context(),
                ]),
            ],
        ]);

        $rule = new SpecInvalid($this->schemaPath);
        $api = makeApiNodeForSpec($spec);
        $context = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

        $findings = iterator_to_array($rule->checkApi($api, $context));

        // Should have findings for the invalid parameter, with route-based
        // human-readable path (falls back to "GET /test" since no descriptor)
        $routeFindings = array_filter(
            $findings,
            static fn($f) => str_contains($f->message, '/test'),
        );

        foreach ($routeFindings as $finding) {
            expect($finding->message)->not->toContain('//');
        }
    },
);
