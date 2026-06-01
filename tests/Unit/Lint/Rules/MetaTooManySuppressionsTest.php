<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\MetaTooManySuppressions;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Lint\SuppressionScope;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeMetaTooManySuppressionsApiNode(): ApiNode
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
}

/**
 * @return list<SuppressionDirective>
 */
function tooManyDirectives(int $count, string $file): array
{
    $directives = [];

    for ($i = 0; $i < $count; $i++) {
        $directives[] = new SuppressionDirective(
            ruleId: 'response.empty',
            reason: 'ok',
            scope: SuppressionScope::ClassScope,
            file: $file,
            line: $i + 1,
            targetClass: 'Acme\\Foo',
        );
    }

    return $directives;
}

it('reports its id and level', function (): void {
    $rule = new MetaTooManySuppressions();

    expect($rule->id())->toBe('meta.too-many-suppressions')
        ->and($rule->level())->toBe(3);
});

it('emits no finding when suppressions are at or below the threshold', function (): void {
    $api = makeMetaTooManySuppressionsApiNode();
    $context = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: tooManyDirectives(5, 'Controller.php'),
    );

    $rule = new MetaTooManySuppressions(threshold: 5);
    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when suppressions exceed the threshold', function (): void {
    $api = makeMetaTooManySuppressionsApiNode();
    $context = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: tooManyDirectives(6, 'Controller.php'),
    );

    $rule = new MetaTooManySuppressions(threshold: 5);
    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('meta.too-many-suppressions')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('Controller.php')
        ->and($findings[0]->message)->toContain('6')
        ->and($findings[0]->message)->toContain('threshold')
        ->and($findings[0]->location->file)->toBe('Controller.php');
});

it('groups suppressions by file and emits per file', function (): void {
    $api = makeMetaTooManySuppressionsApiNode();
    $context = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [
            ...tooManyDirectives(3, 'FileA.php'),
            ...tooManyDirectives(6, 'FileB.php'),
        ],
    );

    $rule = new MetaTooManySuppressions(threshold: 5);
    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('FileB.php');
});

it('respects a custom threshold', function (): void {
    $api = makeMetaTooManySuppressionsApiNode();
    $context = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: tooManyDirectives(3, 'Controller.php'),
    );

    $rule = new MetaTooManySuppressions(threshold: 2);
    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('3')
        ->and($findings[0]->message)->toContain('threshold: 2');
});
