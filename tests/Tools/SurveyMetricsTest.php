<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tools/survey/metrics.php';

it('extracts deterministic baseline metrics from spec + lint', function (): void {
    $spec = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/survey/spec.json'), true);
    $lint = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/survey/lint.json'), true);

    $run = [
        'generateExit' => 0,
        'lintExit' => 1,
        'generateStderr' => false,
        'bootOutcome' => 'booted',
        'routesIntrospected' => 5,
    ];

    $m = surveyMetrics($spec, $lint, $run, '/api');

    // Fixture: 4 ops total (users GET+POST, empty GET, web GET); 3 under /api.
    // users GET -> {data:$ref User(2 props)} = substantive; users POST -> 2-prop
    // body + substantive 201; empty GET -> {data:{object,no props}} = NOT
    // substantive; web GET not under prefix. Complete api ops = users GET + POST.
    expect($m['paths'])->toBe(3)
        ->and($m['operations'])->toBe(4)
        ->and($m['apiOperations'])->toBe(3)
        ->and($m['responseSchemas'])->toBe(2)
        ->and($m['documentedResponses'])->toBe(3)
        ->and($m['requestBodies'])->toBe(1)
        ->and($m['maxRequestProperties'])->toBe(2)
        ->and($m['componentSchemas'])->toBe(1)
        ->and($m['completenessPercent'])->toBe(66.7)
        ->and($m['completenessBasis'])->toBe('strict')
        ->and($m['lintFindings']['total'])->toBe(3)
        ->and($m['lintFindings']['byRule']['response.no-error'])->toBe(2)
        ->and($m['lintFindings']['byLevel'][1])->toBe(3)
        ->and($m['crash']['bootOutcome'])->toBe('booted')
        ->and($m['crash']['routesIntrospected'])->toBe(5)
        ->and($m)->not->toHaveKey('coverage');
});

it('counts only substantive 2xx into responseSchemas, contentless ops into documentedResponses', function (): void {
    // The #254 scenario: a contentless 2xx must NOT inflate responseSchemas (it carries
    // no schema), while still being a documented success outcome. Moving an op from a
    // bare 200 to a 2xx with a not-yet-substantive schema must not drop responseSchemas.
    $spec = ['paths' => [
        '/api/no-content' => ['delete' => ['responses' => ['204' => []]]],
        '/api/empty-schema' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]]],
        ]]]]],
        '/api/substantive' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ]]]]],
    ]];

    $run = ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];

    $m = surveyMetrics($spec, ['findings' => []], $run, '/api');

    // Only /api/substantive carries a real schema.
    expect($m['responseSchemas'])->toBe(1)
        // All three document a 2xx outcome (contentless 204, empty-schema 200, substantive 200).
        ->and($m['documentedResponses'])->toBe(3)
        // Without a classification the basis is strict: only the substantive GET is complete.
        ->and($m['completenessBasis'])->toBe('strict')
        ->and($m['completenessPercent'])->toBe(33.3);
});

it('omits responseCoverage when no classification is supplied', function (): void {
    $spec = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/survey/spec.json'), true);
    $run = ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];

    $m = surveyMetrics($spec, ['findings' => []], $run, '/api');

    expect($m)->not->toHaveKey('responseCoverage');
});

it('splits in-prefix ops into substantive / correctly-empty / genuinely-missing with a classification', function (): void {
    // Three in-prefix ops: one substantive, one genuinely no-content (void), one that returns a
    // body the generator could not resolve (empty-schema 200). The contentless vs give-up split
    // can only come from the action source shape, not the spec.
    $spec = ['paths' => [
        '/api/show/{id}' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ]]]]],
        '/api/destroy/{id}' => ['delete' => ['responses' => ['204' => []]]],
        '/api/dynamic' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
        ]]]]],
    ]];

    $run = ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];

    $classification = [
        ['uri' => '/api/show/{id}', 'verb' => 'get', 'shape' => 'resource::make', 'returnType' => 'JsonResource'],
        ['uri' => '/api/destroy/{id}', 'verb' => 'delete', 'shape' => 'no return (void-like)', 'returnType' => 'void'],
        ['uri' => '/api/dynamic', 'verb' => 'get', 'shape' => 'response()->json(<non-literal>)', 'returnType' => 'JsonResponse'],
    ];

    $m = surveyMetrics($spec, ['findings' => []], $run, '/api', null, $classification);

    expect($m['responseCoverage']['substantive'])->toBe(1)
        ->and($m['responseCoverage']['correctlyEmpty'])->toBe(1)
        ->and($m['responseCoverage']['genuinelyMissing'])->toBe(1)
        ->and($m['responseCoverage']['genuinelyMissingByShape'])->toBe(['response()->json(<non-literal>)' => 1])
        // The three buckets partition apiOperations exactly.
        ->and($m['responseCoverage']['substantive'] + $m['responseCoverage']['correctlyEmpty'] + $m['responseCoverage']['genuinelyMissing'])
        ->toBe($m['apiOperations']);
});

it('counts a non-substantive op with no classification record as genuinely-missing (conservative)', function (): void {
    $spec = ['paths' => [
        '/api/mystery' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
        ]]]]],
    ]];
    $run = ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];

    // Classification supplied but missing this op's record.
    $m = surveyMetrics($spec, ['findings' => []], $run, '/api', null, []);

    expect($m['responseCoverage']['genuinelyMissing'])->toBe(1)
        ->and($m['responseCoverage']['correctlyEmpty'])->toBe(0)
        ->and($m['responseCoverage']['genuinelyMissingByShape'])->toBe(['unclassified' => 1]);
});

it('maps detected integration packages to their plugin class-strings (stack-enabled variant)', function (): void {
    expect(survey_stackPlugins(['league/fractal']))
        ->toBe(['Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin'])
        ->and(survey_stackPlugins(['spatie/laravel-query-builder']))
        ->toBe(['Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin'])
        // spatie/laravel-fractal also maps to the Fractal plugin; deduped when both are present.
        ->and(survey_stackPlugins(['league/fractal', 'spatie/laravel-fractal']))
        ->toBe(['Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin'])
        ->and(survey_stackPlugins(['league/fractal', 'spatie/laravel-query-builder', 'unknown/pkg']))
        ->toBe([
            'Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin',
            'Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin',
        ])
        ->and(survey_stackPlugins([]))->toBe([]);
});

it('emits responseCoverageStackEnabled beside responseCoverage when a stack spec is present (CLI)', function (): void {
    // The stack spec resolves /api/dynamic's body (as a stack-implied plugin would); the default spec
    // leaves it an empty-schema 200. The same classification joins both specs, so the stack variant
    // lifts the op from genuinely-missing to substantive while the out-of-box block still reports it missing.
    $appDir = sys_get_temp_dir() . '/survey-metrics-' . bin2hex(random_bytes(6));
    mkdir($appDir, 0o777, true);

    $unresolved = ['paths' => ['/api/dynamic' => ['get' => ['responses' => ['200' => ['content' => [
        'application/json' => ['schema' => ['type' => 'object']],
    ]]]]]]];
    $resolved = ['paths' => ['/api/dynamic' => ['get' => ['responses' => ['200' => ['content' => [
        'application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
    ]]]]]]];

    file_put_contents("$appDir/generated-spec.json", (string) json_encode($unresolved));
    file_put_contents("$appDir/generated-spec.stack.json", (string) json_encode($resolved));
    file_put_contents("$appDir/classify.json", (string) json_encode([
        ['uri' => '/api/dynamic', 'verb' => 'get', 'shape' => 'response()->json(<non-literal>)', 'returnType' => 'JsonResponse'],
    ]));
    file_put_contents("$appDir/lint.json", (string) json_encode(['findings' => []]));
    file_put_contents("$appDir/run.json", (string) json_encode([
        'generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted',
    ]));

    $script = __DIR__ . '/../../tools/survey/metrics.php';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($appDir) . ' --prefix=/api 2>&1', $stdout, $exit);

    expect($exit)->toBe(0);

    $m = json_decode(implode("\n", $stdout), true);

    expect($m['responseCoverage']['substantive'])->toBe(0)
        ->and($m['responseCoverage']['genuinelyMissing'])->toBe(1)
        // Reported beside the out-of-box block, from the same classification join against the stack spec.
        ->and($m['responseCoverageStackEnabled']['substantive'])->toBe(1)
        ->and($m['responseCoverageStackEnabled']['genuinelyMissing'])->toBe(0);

    array_map('unlink', array_filter(glob($appDir . '/*') ?: [], 'is_file'));
    rmdir($appDir);
});

it('adds coverage when a published spec is supplied', function (): void {
    $spec = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/survey/spec.json'), true);
    $lint = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/survey/lint.json'), true);

    // Published declares /api/users GET and /api/gone GET; we cover the first only.
    $published = ['paths' => [
        '/api/users' => ['get' => []],
        '/api/gone' => ['get' => []],
    ]];

    $m = surveyMetrics($spec, $lint, ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted', 'routesIntrospected' => 5], '/api', $published);

    expect($m['coverage']['publishedOps'])->toBe(2)
        ->and($m['coverage']['intersection'])->toBe(1)
        ->and($m['coverage']['covPercent'])->toBe(50.0);
});

it('picks the first existing file from the autoloader candidate list', function (): void {
    expect(survey_firstExistingFile(['/no/such/file', __FILE__, '/another/missing']))->toBe(__FILE__)
        ->and(survey_firstExistingFile(['/no/such/file', '/also/missing']))->toBeNull();
});

/**
 * A run record with no crash signal — the crash block is not under test in the scoring cases.
 *
 * @return array{generateExit:int,lintExit:int,generateStderr:bool,bootOutcome:string}
 */
function survey_metrics_cleanRun(): array
{
    return ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];
}

/**
 * A JSON:API resource-object schema carrying the given attribute properties.
 *
 * @param array<string, mixed> $attributeProperties
 * @param array<string, mixed> $extra               additional sibling properties of the resource object
 *
 * @return array<string, mixed>
 */
function survey_metrics_jsonApiResource(array $attributeProperties, array $extra = []): array
{
    return ['type' => 'object', 'properties' => [
        'type' => ['type' => 'string'],
        'id' => ['type' => 'string'],
        'attributes' => ['type' => 'object', 'properties' => $attributeProperties],
    ] + $extra];
}

it('scores a JSON:API resource object by its attributes, not by its envelope keys', function (): void {
    $components = [];

    // Bare {type,id} carries no payload; the same object with populated attributes does.
    expect(survey_substantive(['type' => 'object', 'properties' => [
        'type' => ['type' => 'string'],
        'id' => ['type' => 'string'],
    ]], $components))->toBeFalse()
        ->and(survey_substantive(survey_metrics_jsonApiResource(['name' => ['type' => 'string']]), $components))
        ->toBeTrue()
        ->and(survey_substantive(survey_metrics_jsonApiResource([]), $components))->toBeFalse()
        // The envelope keys may accompany it; the verdict still comes from attributes alone.
        ->and(survey_substantive(survey_metrics_jsonApiResource([], [
            'relationships' => ['type' => 'object'],
            'links' => ['type' => 'object'],
            'meta' => ['type' => 'object'],
        ]), $components))->toBeFalse()
        // Composed through the {data:…} unwrap and a collection.
        ->and(survey_substantive([
            'type' => 'object',
            'properties' => ['data' => [
                'type' => 'array',
                'items' => survey_metrics_jsonApiResource(['name' => ['type' => 'string']]),
            ]],
        ], $components))->toBeTrue()
        ->and(survey_substantive([
            'type' => 'object',
            'properties' => ['data' => ['type' => 'array', 'items' => survey_metrics_jsonApiResource([])]],
        ], $components))->toBeFalse();
});

it('leaves a look-alike object with an unrelated property substantive', function (): void {
    // The unwrap keys off a closed key set, so {type,id,foo} is an ordinary three-property object.
    expect(survey_substantive(['type' => 'object', 'properties' => [
        'type' => ['type' => 'string'],
        'id' => ['type' => 'string'],
        'foo' => ['type' => 'string'],
    ]], []))->toBeTrue();
});

it('keeps the pre-existing substantive branches intact', function (): void {
    $components = ['Cycle' => ['$ref' => '#/components/schemas/Cycle']];

    expect(survey_substantive(['type' => 'string'], []))->toBeTrue()
        ->and(survey_substantive(['type' => ['string', 'null']], []))->toBeTrue()
        ->and(survey_substantive(['type' => 'object', 'additionalProperties' => ['type' => 'string']], []))->toBeTrue()
        ->and(survey_substantive(['allOf' => [['type' => 'object'], ['type' => 'string']]], []))->toBeTrue()
        ->and(survey_substantive(['$ref' => '#/components/schemas/Cycle'], $components))->toBeFalse()
        ->and(survey_substantive(['type' => 'object'], []))->toBeFalse();
});

it('credits only an affirmative no-content 2xx, never a contentless 200', function (): void {
    $spec = ['paths' => [
        // Affirmative no-content, with a classification supplied but no record for this op.
        '/api/destroy/{id}' => ['delete' => ['responses' => ['204' => []]]],
        // The generator's give-up path: a contentless 200 on an action that returns a body.
        '/api/give-up' => ['get' => ['responses' => ['200' => []]]],
        // A contentless 200 the classification confirms is genuinely body-less.
        '/api/void' => ['get' => ['responses' => ['200' => []]]],
        // A contentless 202 is deliberately not affirmative under the narrow rule.
        '/api/accepted' => ['post' => ['responses' => ['202' => []]]],
        // An empty {} schema is not a response shape at all.
        '/api/empty-schema' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
        ]]]]],
    ]];

    $classification = [
        ['uri' => '/api/give-up', 'verb' => 'get', 'shape' => 'response()->json(<non-literal>)', 'returnType' => 'JsonResponse'],
        ['uri' => '/api/void', 'verb' => 'get', 'shape' => 'void/no-body', 'returnType' => 'void'],
        ['uri' => '/api/accepted', 'verb' => 'post', 'shape' => 'response()->json(<non-literal>)', 'returnType' => 'JsonResponse'],
        ['uri' => '/api/empty-schema', 'verb' => 'get', 'shape' => 'response()->json(<non-literal>)', 'returnType' => 'JsonResponse'],
    ];

    $m = surveyMetrics($spec, ['findings' => []], survey_metrics_cleanRun(), '/api', null, $classification);

    expect($m['responseCoverage']['correctlyEmpty'])->toBe(2)
        ->and($m['responseCoverage']['genuinelyMissing'])->toBe(3)
        ->and($m['responseCoverage']['substantive'])->toBe(0)
        // Only the give-up cases stay missing; the 204 and the classified void are correctly empty.
        ->and($m['completenessBasis'])->toBe('classified')
        ->and($m['completenessPercent'])->toBe(40.0);
});

it('partitions the request-body axis into documented / undocumented-on-write / not-applicable', function (): void {
    $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
        'name' => ['type' => 'string'],
    ]]]]];
    $substantive = ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
        'id' => ['type' => 'integer'],
    ]]]]]];

    $spec = ['paths' => [
        '/api/store' => ['post' => ['requestBody' => $body, 'responses' => $substantive]],
        // A body-less write is unresolved on the body axis, and still complete on the response axis.
        '/api/toggle' => ['post' => ['responses' => $substantive]],
        // A requestBody whose media types carry no schema documents nothing.
        '/api/upload' => ['put' => ['requestBody' => ['content' => ['multipart/form-data' => []]], 'responses' => $substantive]],
        '/api/index' => ['get' => ['responses' => $substantive]],
        '/api/destroy/{id}' => ['delete' => ['responses' => $substantive]],
    ]];

    $m = surveyMetrics($spec, ['findings' => []], survey_metrics_cleanRun(), '/api');

    expect($m['requestBodyCoverage'])->toBe([
        'documented' => 1,
        'undocumentedOnWrite' => 2,
        'notApplicable' => 2,
    ])
        // The three buckets partition apiOperations exactly.
        ->and(array_sum($m['requestBodyCoverage']))->toBe($m['apiOperations'])
        // The verb no longer gates the percentage: all five responses are substantive.
        ->and($m['completenessPercent'])->toBe(100.0)
        ->and($m['requestBodies'])->toBe(1);
});

it('reproduces the gate-load-bearing response metrics bit-identically', function (): void {
    // Mixes contentless 2xx, empty-schema 2xx, substantive 2xx and no-2xx ops. The expected
    // values are what the pre-fix scorer produced; documentedResponses, responseSchemas and
    // maxRequestProperties are out of scope and must not move.
    $spec = ['paths' => [
        '/api/no-content' => ['delete' => ['responses' => ['204' => []]]],
        '/api/contentless-200' => ['get' => ['responses' => ['200' => []]]],
        '/api/empty-schema' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
        ]]]]],
        '/api/substantive' => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'role' => ['type' => 'string'],
            ]]]]],
            'responses' => ['201' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
            ]]]]]],
        ]],
        '/api/no-2xx' => ['get' => ['responses' => ['404' => []]]],
    ]];

    $m = surveyMetrics($spec, ['findings' => []], survey_metrics_cleanRun(), '/api');

    expect($m['apiOperations'])->toBe(5)
        ->and($m['documentedResponses'])->toBe(4)
        ->and($m['responseSchemas'])->toBe(1)
        ->and($m['maxRequestProperties'])->toBe(3);
});

it('holds the basis rule in one place for both bases', function (): void {
    $spec = ['paths' => [
        '/api/show/{id}' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ]]]]],
        '/api/destroy/{id}' => ['delete' => ['responses' => ['204' => []]]],
        '/api/dynamic' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
        ]]]]],
    ]];
    $classification = [
        ['uri' => '/api/destroy/{id}', 'verb' => 'delete', 'shape' => 'void/no-body', 'returnType' => 'void'],
    ];

    $strict = surveyMetrics($spec, ['findings' => []], survey_metrics_cleanRun(), '/api');
    $classified = surveyMetrics($spec, ['findings' => []], survey_metrics_cleanRun(), '/api', null, $classification);

    expect($strict['completenessBasis'])->toBe('strict')
        ->and($strict)->not->toHaveKey('responseCoverage')
        // Strict counts substantive only, so the 204 op is not complete.
        ->and($strict['completenessPercent'])->toBe(33.3)
        ->and($classified['completenessBasis'])->toBe('classified')
        ->and($classified['completenessPercent'])->toBe(66.7)
        ->and($classified['responseCoverage']['substantive']
            + $classified['responseCoverage']['correctlyEmpty']
            + $classified['responseCoverage']['genuinelyMissing'])->toBe($classified['apiOperations']);

    // survey_isComplete() returns the same verdict the aggregate counted, for every op.
    $verdicts = ['strict' => 0, 'classified' => 0];
    $index = survey_classificationIndex($classification);

    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $key = strtoupper((string) $method) . ' ' . preg_replace('/\{[^}]+\}/', '{}', (string) $path);
            $outcome = survey_operationOutcome($operation, (string) $method, [], $index[$key] ?? null);

            foreach (['strict', 'classified'] as $basis) {
                $verdicts[$basis] += survey_isComplete($outcome, $basis) ? 1 : 0;
            }
        }
    }

    expect($verdicts['strict'])->toBe(1)
        ->and($verdicts['classified'])->toBe(2);
});

it('counts operations carrying security, so a drop reads as the regression it is', function (): void {
    $spec = ['paths' => [
        '/api/open' => ['get' => ['responses' => ['200' => []]]],
        '/api/guarded' => ['get' => ['security' => [['sanctum' => []]], 'responses' => ['200' => []]]],
        '/api/also-guarded' => ['post' => ['security' => [], 'responses' => ['200' => []]]],
    ]];

    $m = surveyMetrics($spec, ['findings' => []], survey_metrics_cleanRun(), '/api');

    // An explicit empty security array is still a documented security decision.
    expect($m['operationsWithSecurity'])->toBe(2)
        ->and($m['apiOperations'] - $m['operationsWithSecurity'])->toBe(1);
});
