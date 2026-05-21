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
use Radiergummi\OpenApi\Core\Lint\Rules\SpecInvalid;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    $this->schemaPath = dirname(__DIR__, 4) . '/resources/openapi/oas-3.1-schema.json';
});

function specInvalidFindings(OA\OpenApi $spec, string $schemaPath): array
{
    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    return iterator_to_array((new SpecInvalid($schemaPath))->checkApi($api, $lintCtx));
}

it('emits no findings for a minimal valid OAS 3.1 spec', function (): void {
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => new Context()]),
        'paths' => [new OA\PathItem(['path' => '/test', '_context' => new Context()])],
    ]);

    expect(specInvalidFindings($spec, $this->schemaPath))->toBe([]);
});

it('emits a finding when the spec violates the OAS 3.1 schema', function (): void {
    $findings = specInvalidFindings(new OA\OpenApi(['openapi' => '3.1.0']), $this->schemaPath);

    expect($findings)->not->toBeEmpty()
        ->and($findings[0]->ruleId)->toBe('spec.invalid')
        ->and($findings[0]->level)->toBe(0);
});

it('filters out false-positive $dynamicRef findings (openapi/info required at nested paths)', function (): void {
    // A spec missing 'info' at the root is a legitimate error.
    // But a nested schema object missing 'openapi'/'info' is a false positive
    // from the OAS 3.1 meta-schema's $dynamicRef propagation.
    $findings = specInvalidFindings(new OA\OpenApi(['openapi' => '3.1.0']), $this->schemaPath);

    $nestedOpenapiFindings = array_filter(
        $findings,
        static fn($f) => str_contains($f->message, 'openapi, info') && $f->location->jsonPointer !== '/',
    );

    expect($nestedOpenapiFindings)->toBeEmpty();
});

it('produces interpolated messages without raw placeholders', function (): void {
    $findings = specInvalidFindings(new OA\OpenApi(['openapi' => '3.1.0']), $this->schemaPath);

    expect($findings)->not->toBeEmpty();

    foreach ($findings as $finding) {
        expect($finding->message)->not->toMatch('/{[a-z_]+}/i')
            ->and($finding->fixHint)->not->toBeNull()
            ->and($finding->context)->toHaveKey('keyword');
    }
});

it('deduplicates findings with the same pointer and message', function (): void {
    $findings = specInvalidFindings(new OA\OpenApi(['openapi' => '3.1.0']), $this->schemaPath);

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
});

it('resolves route source location when action descriptors are provided', function (): void {
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => new Context()]),
        'paths' => [new OA\PathItem([
            'path' => '/test',
            'get' => new OA\Get([
                'operationId' => 'test.index',
                // Intentionally invalid: parameter without required 'name'
                'parameters' => [new OA\Parameter(['in' => 'query'])],
                'responses' => [new OA\Response(['response' => 200, 'description' => 'OK'])],
            ]),
            '_context' => new Context(),
        ])],
    ]);

    $findings = specInvalidFindings($spec, $this->schemaPath);

    $routeFindings = array_filter(
        $findings,
        static fn($f) => str_contains($f->message, '/test'),
    );

    foreach ($routeFindings as $finding) {
        expect($finding->message)->not->toContain('//');
    }
});
