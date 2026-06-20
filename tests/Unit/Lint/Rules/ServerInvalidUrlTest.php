<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\ServerInvalidUrl;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function serverInvalidUrlFindings(?string $url): array
{
    $context = new Context();
    $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $context]);

    if ($url !== null) {
        $spec->servers = [new OA\Server(['url' => $url, '_context' => $context])];
    }

    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    return iterator_to_array(new ServerInvalidUrl()->checkApi($api, $lintCtx));
}

it('has the correct rule id and level', function (): void {
    $rule = new ServerInvalidUrl();

    expect($rule->id)->toBe('server.invalid-url')
        ->and($rule->severity)->toBe(Severity::Broken);
});

it('emits a finding for an invalid URL', function (): void {
    $findings = serverInvalidUrlFindings('not a url');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('server.invalid-url')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('not a url');
});

it('emits no findings', function (?string $url): void {
    expect(serverInvalidUrlFindings($url))->toBe([]);
})->with([
    'valid URL' => 'https://api.example.com',
    'URL with template variables' => 'https://{region}.example.com',
    'root path' => '/',
    'versioned path' => '/api/v0',
    'short path' => '/v1',
    'servers is UNDEFINED' => null,
]);
