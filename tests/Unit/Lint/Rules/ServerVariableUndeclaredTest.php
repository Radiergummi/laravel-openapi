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
use Radiergummi\OpenApi\Core\Lint\Rules\ServerVariableUndeclared;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function serverVariableUndeclaredFindings(?string $url, array $variables = []): array
{
    $context = new Context();
    $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $context]);

    if ($url !== null) {
        $serverData = ['url' => $url, '_context' => $context];

        if ($variables !== []) {
            $vars = [];

            foreach ($variables as $name => $default) {
                $vars[$name] = new OA\ServerVariable(['serverVariable' => $name, 'default' => $default, '_context' => $context]);
            }
            $serverData['variables'] = $vars;
        }

        $spec->servers = [new OA\Server($serverData)];
    }

    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $lintCtx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $spec, actionDescriptors: [], suppressions: []);

    return iterator_to_array(new ServerVariableUndeclared()->checkApi($api, $lintCtx));
}

it('has the correct rule id and level', function (): void {
    $rule = new ServerVariableUndeclared();

    expect($rule->id())->toBe('server.variable-undeclared')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when a template variable has no matching variables entry', function (): void {
    $findings = serverVariableUndeclaredFindings('https://{region}.example.com');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('server.variable-undeclared')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('region');
});

it('emits no findings', function (?string $url, array $variables = []): void {
    expect(serverVariableUndeclaredFindings($url, $variables))->toBe([]);
})->with([
    'all template variables declared' => ['https://{region}.example.com', ['region' => 'eu']],
    'no template variables in URL' => ['https://api.example.com', []],
    'servers is UNDEFINED' => [null, []],
]);
