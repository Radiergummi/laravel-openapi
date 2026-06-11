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
