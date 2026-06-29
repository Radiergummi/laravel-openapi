<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tools/survey/typedness.php';

/**
 * A 4-operation /api surface exercising every return-shape class:
 *   GET  /api/items  -> substantive collection, classified `resource::collection` (typed, covered)
 *   POST /api/items  -> substantive 201,        classified `new ItemResource(...)` (typed, covered)
 *   GET  /api/ping   -> contentless 204,        classified `no return (void-like)` (correctly-empty)
 *   GET  /api/dump   -> empty {data:{}} 200,     classified `response()->json(<non-literal>)` (dynamic, uncovered)
 */
function survey_typedness_fixtureSpec(): array
{
    $object = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    return ['paths' => [
        '/api/items' => [
            'get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => $object]]]]],
            'post' => ['responses' => ['201' => ['content' => ['application/json' => ['schema' => $object]]]]],
        ],
        '/api/ping' => ['get' => ['responses' => ['204' => []]]],
        '/api/dump' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'type' => 'object', 'properties' => ['data' => ['type' => 'object']],
        ]]]]]]],
    ]];
}

function survey_typedness_fixtureClassify(): array
{
    return [
        ['uri' => '/api/items', 'verb' => 'get', 'shape' => 'resource::collection'],
        ['uri' => '/api/items', 'verb' => 'post', 'shape' => 'new ItemResource(...)'],
        ['uri' => '/api/ping', 'verb' => 'get', 'shape' => 'no return (void-like)'],
        ['uri' => '/api/dump', 'verb' => 'get', 'shape' => 'response()->json(<non-literal>)'],
    ];
}

it('segments operations by return shape and reports conditional coverage', function (): void {
    $metrics = typednessMetrics(survey_typedness_fixtureSpec(), survey_typedness_fixtureClassify(), '/api');

    expect($metrics['apiOperations'])->toBe(4)
        ->and($metrics['substantiveResponses'])->toBe(2)
        ->and($metrics['substantivePercent'])->toBe(50.0)
        ->and($metrics['classified'])->toBeTrue()
        ->and($metrics['typedActions'])->toBe(2)
        ->and($metrics['typedCovered'])->toBe(2)
        ->and($metrics['typedReturnCoverage'])->toBe(100.0)
        ->and($metrics['dynamicActions'])->toBe(1)
        ->and($metrics['dynamicCovered'])->toBe(0)
        ->and($metrics['correctlyEmptyActions'])->toBe(1)
        // honest% removes the one correctly-empty op from the denominator: 2 / (4 - 1).
        ->and($metrics['honestPercent'])->toBe(66.7);
});

it('reproduces metrics.php apiOperations/responseSchemas by construction', function (): void {
    $spec = survey_typedness_fixtureSpec();
    $run = ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];
    $base = surveyMetrics($spec, ['findings' => []], $run, '/api');
    $metrics = typednessMetrics($spec, survey_typedness_fixtureClassify(), '/api');

    expect($metrics['apiOperations'])->toBe($base['apiOperations'])
        ->and($metrics['substantiveResponses'])->toBe($base['responseSchemas']);
});

it('emits only the spec-only substantive figure when no classifier artifact is present', function (): void {
    $metrics = typednessMetrics(survey_typedness_fixtureSpec(), null, '/api');

    expect($metrics['apiOperations'])->toBe(4)
        ->and($metrics['substantiveResponses'])->toBe(2)
        ->and($metrics['substantivePercent'])->toBe(50.0)
        ->and($metrics['classified'])->toBeFalse()
        ->and($metrics['typedActions'])->toBeNull()
        ->and($metrics['typedReturnCoverage'])->toBeNull()
        ->and($metrics['correctlyEmptyActions'])->toBeNull()
        ->and($metrics['honestPercent'])->toBeNull();
});
