<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseRedirectWithoutLocation;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeRedirectResponseNode(
    string $statusCode,
    array $headers = [],
    string $pathUri = '/old',
    string $method = 'GET',
): ResponseNode {
    $headerNodes = [];

    foreach ($headers as $name) {
        $headerNodes[] = new HeaderNode(
            name: $name,
            schema: null,
            description: null,
            required: false,
            raw: null,
        );
    }

    $response = new ResponseNode(
        statusCode: $statusCode,
        description: null,
        fields: [],
        examples: [],
        schemaRef: null,
        headers: $headerNodes,
        links: [],
        raw: null,
    );

    $operation = new OperationNode(
        pathUri: $pathUri,
        method: $method,
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [$response],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
    $response->linkParent($operation);

    foreach ($headerNodes as $headerNode) {
        $headerNode->linkParent($response);
    }

    return $response;
}

function makeRedirectWithoutLocationContext(): LintContext
{
    $spec = new OA\OpenApi(['_context' => new Context()]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new ResponseRedirectWithoutLocation();

    expect($rule->id())->toBe('response.redirect-without-location')->and($rule->level())->toBe(2);
});

it('emits no finding when a 301 response has a Location header', function (): void {
    $response = makeRedirectResponseNode('301', headers: ['Location']);
    $context = makeRedirectWithoutLocationContext();

    $findings = iterator_to_array(
        new ResponseRedirectWithoutLocation()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a non-redirect response has no Location header', function (): void {
    $response = makeRedirectResponseNode('200');
    $context = makeRedirectWithoutLocationContext();

    $findings = iterator_to_array(
        new ResponseRedirectWithoutLocation()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a 302 response has no Location header', function (): void {
    $response = makeRedirectResponseNode('302', headers: []);
    $context = makeRedirectWithoutLocationContext();

    $findings = iterator_to_array(
        new ResponseRedirectWithoutLocation()->checkResponse($response, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('response.redirect-without-location')
        ->and($findings[0]->level)
        ->toBe(2)
        ->and($findings[0]->message)
        ->toContain('302')
        ->and($findings[0]->message)
        ->toContain('GET')
        ->and($findings[0]->message)
        ->toContain('/old')
        ->and($findings[0]->message)
        ->toContain('no Location header');
});

it(
    'does not flag a 3xx response that has a Location header among other headers',
    function (): void {
        $response = makeRedirectResponseNode('301', headers: ['X-Custom', 'Location']);
        $context = makeRedirectWithoutLocationContext();

        $findings = iterator_to_array(
            new ResponseRedirectWithoutLocation()->checkResponse($response, $context),
        );

        expect($findings)->toBe([]);
    },
);

it('matches Location header case-insensitively', function (): void {
    $response = makeRedirectResponseNode('301', headers: ['location']);
    $context = makeRedirectWithoutLocationContext();

    $findings = iterator_to_array(
        new ResponseRedirectWithoutLocation()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});
