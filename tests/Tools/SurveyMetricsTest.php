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
        // Completeness still credits the contentless 204 (parity with completeness.php),
        // but not the empty-schema 200: substantive GET + no-content DELETE = 2 of 3.
        ->and($m['completenessPercent'])->toBe(66.7);
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
