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
use Radiergummi\OpenApi\Core\Lint\Rules\ServerInvalidUrl;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeServerInvalidUrlContext(OA\OpenApi $spec): LintContext
{
    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makeSpecWithServer(string $url): OA\OpenApi
{
    $ctx = new Context();
    $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $ctx]);
    $server = new OA\Server(['url' => $url, '_context' => $ctx]);
    $spec->servers = [$server];

    return $spec;
}

it('has the correct rule id and level', function (): void {
    $rule = new ServerInvalidUrl();

    expect($rule->id())->toBe('server.invalid-url')
        ->and($rule->level())->toBe(0);
});

it('emits a finding for an invalid URL', function (): void {
    $rule = new ServerInvalidUrl();
    $spec = makeSpecWithServer('not a url');
    $context = makeServerInvalidUrlContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('server.invalid-url')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('not a url');
});

it('emits no findings for a valid URL', function (): void {
    $rule = new ServerInvalidUrl();
    $spec = makeSpecWithServer('https://api.example.com');
    $context = makeServerInvalidUrlContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits no findings for a URL with template variables that strips to a valid URL', function (): void {
    $rule = new ServerInvalidUrl();
    $spec = makeSpecWithServer('https://{region}.example.com');
    $context = makeServerInvalidUrlContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits no findings for a relative URL path', function (string $url): void {
    $rule = new ServerInvalidUrl();
    $spec = makeSpecWithServer($url);
    $context = makeServerInvalidUrlContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
})->with([
    'root path' => ['/'],
    'versioned path' => ['/api/v0'],
    'short path' => ['/v1'],
]);

it('emits no findings when servers is UNDEFINED', function (): void {
    $rule = new ServerInvalidUrl();
    $ctx = new Context();
    $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $ctx]);
    $context = makeServerInvalidUrlContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});
