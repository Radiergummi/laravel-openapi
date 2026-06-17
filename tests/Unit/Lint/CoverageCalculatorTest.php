<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\CoverageCalculator;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;

uses()->group('openapi', 'lint');

/**
 * @param list<string> $tags
 */
function op(string $spec, HttpMethod $method, string $uri, array $tags = []): array
{
    return [CoverageCalculator::operationKey($spec, $method, $uri), $tags];
}

function findingOn(string $spec, HttpMethod $method, string $uri): Finding
{
    return new Finding(
        ruleId: 'response.empty',
        severity: Severity::Degraded,
        message: 'x',
        location: new FindingLocation(routeMethod: $method, routeUri: $uri),
        spec: $spec,
    );
}

it('reports 100% when every operation is clean', function (): void {
    [$k1, $t1] = op('default', HttpMethod::Get, 'users');
    [$k2, $t2] = op('default', HttpMethod::Post, 'users');

    $summary = new CoverageCalculator()->calculate(
        operationTags: [$k1 => $t1, $k2 => $t2],
        findings: [],
        level: 1,
        generatorVersion: '1.2.3',
    );

    expect($summary->totalOperations)
        ->toBe(2)
        ->and($summary->coveredOperations)->toBe(2)
        ->and($summary->coveragePercent)->toBe(100.00)
        ->and($summary->generatorVersion)->toBe('1.2.3')
        ->and($summary->unattributedFindings)->toBe(0);
});

it('marks an operation uncovered when it carries an attributed finding', function (): void {
    [$k1, $t1] = op('default', HttpMethod::Get, 'users');
    [$k2, $t2] = op('default', HttpMethod::Post, 'users');

    $summary = new CoverageCalculator()->calculate(
        operationTags: [$k1 => $t1, $k2 => $t2],
        findings: [findingOn('default', HttpMethod::Post, 'users')],
        level: 1,
        generatorVersion: 'dev',
    );

    expect($summary->coveredOperations)
        ->toBe(1)
        ->and($summary->coveragePercent)->toBe(50.00);
});

it('counts findings with no operation as unattributed without lowering coverage', function (): void {
    [$k1, $t1] = op('default', HttpMethod::Get, 'users');

    $preBuild = new Finding(ruleId: 'overrides.unknown-field', severity: Severity::Degraded, message: 'x');

    $summary = new CoverageCalculator()->calculate(
        operationTags: [$k1 => $t1],
        findings: [$preBuild],
        level: 1,
        generatorVersion: 'dev',
    );

    expect($summary->coveredOperations)
        ->toBe(1)
        ->and($summary->coveragePercent)->toBe(100.00)
        ->and($summary->unattributedFindings)->toBe(1);
});

it('rolls up per-tag coverage, double-counting multi-tag operations and bucketing untagged', function (): void {
    [$k1, $t1] = op('default', HttpMethod::Get, 'users', ['Users', 'Admin']);
    [$k2, $t2] = op('default', HttpMethod::Post, 'users', ['Users']);
    [$k3, $t3] = op('default', HttpMethod::Get, 'ping', []);

    $summary = new CoverageCalculator()->calculate(
        operationTags: [$k1 => $t1, $k2 => $t2, $k3 => $t3],
        findings: [findingOn('default', HttpMethod::Post, 'users')],
        level: 1,
        generatorVersion: 'dev',
    );

    $byTag = collect($summary->perTag)->keyBy('tag');

    expect($byTag['Users'])
        ->toMatchArray(['total' => 2, 'covered' => 1, 'percent' => 50.00])
        ->and($byTag['Admin'])->toMatchArray(['total' => 1, 'covered' => 1, 'percent' => 100.00])
        ->and($byTag['(untagged)'])->toMatchArray(['total' => 1, 'covered' => 1, 'percent' => 100.00]);
});

it('defines empty scope as 100%', function (): void {
    $summary = new CoverageCalculator()->calculate(
        operationTags: [],
        findings: [],
        level: 1,
        generatorVersion: 'dev',
    );

    expect($summary->totalOperations)
        ->toBe(0)
        ->and($summary->coveragePercent)->toBe(100.00);
});

it('rounds the coverage percent to two decimals', function (): void {
    $ops = [];

    foreach (range(1, 3) as $i) {
        [$k, $t] = op('default', HttpMethod::Get, "r{$i}");
        $ops[$k] = $t;
    }

    // 2 of 3 covered → 66.666… → 66.67
    $summary = new CoverageCalculator()->calculate(
        operationTags: $ops,
        findings: [findingOn('default', HttpMethod::Get, 'r1')],
        level: 1,
        generatorVersion: 'dev',
    );

    expect($summary->coveragePercent)->toBe(66.67);
});

it('records a per-operation source location and covered flag for line-keyed reports', function (): void {
    [$k1, $t1] = op('default', HttpMethod::Get, 'users');
    [$k2, $t2] = op('default', HttpMethod::Post, 'users');

    $summary = new CoverageCalculator()->calculate(
        operationTags: [$k1 => $t1, $k2 => $t2],
        findings: [findingOn('default', HttpMethod::Post, 'users')],
        level: 1,
        generatorVersion: 'dev',
        operationLocations: [
            $k1 => ['file' => '/app/UserController.php', 'line' => 20],
            $k2 => ['file' => '/app/UserController.php', 'line' => 33],
        ],
    );

    expect($summary->perOperation)->toBe([
        ['file' => '/app/UserController.php', 'line' => 20, 'covered' => true],
        ['file' => '/app/UserController.php', 'line' => 33, 'covered' => false],
    ]);
});

it('leaves perOperation empty when no locations are supplied (JSON/gate path unchanged)', function (): void {
    [$k1, $t1] = op('default', HttpMethod::Get, 'users');

    $summary = new CoverageCalculator()->calculate([$k1 => $t1], [], 1, 'dev');

    expect($summary->perOperation)->toBe([]);
});

it('matches a finding whose URI has a leading slash to an operation key without one', function (): void {
    [$k, $t] = op('default', HttpMethod::Get, 'users');          // no slash
    $finding = findingOn('default', HttpMethod::Get, '/users');  // leading slash

    $summary = new CoverageCalculator()->calculate([$k => $t], [$finding], 1, 'dev');

    expect($summary->coveredOperations)
        ->toBe(0)
        ->and($summary->unattributedFindings)->toBe(0);
});
