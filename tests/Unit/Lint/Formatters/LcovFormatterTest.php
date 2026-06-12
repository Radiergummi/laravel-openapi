<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Formatters\LcovFormatter;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

function renderLcov(CoverageSummary $coverage): string
{
    $output = new BufferedOutput();
    new LcovFormatter()->render(new LintResult(findings: [], level: 1, exitCode: 0, coverage: $coverage), $output);

    return $output->fetch();
}

function buildLcovCoverage(array $perOperation, string $version = '9.9.9'): CoverageSummary
{
    return new CoverageSummary(
        generatorVersion: $version,
        level: 1,
        totalOperations: count($perOperation),
        coveredOperations: 0,
        coveragePercent: 0.0,
        unattributedFindings: 0,
        perTag: [],
        perOperation: $perOperation,
    );
}

it('emits one record per source file with DA lines from the covered flag', function (): void {
    $lcov = renderLcov(buildLcovCoverage([
        ['file' => 'app/A.php', 'line' => 10, 'covered' => true],
        ['file' => 'app/A.php', 'line' => 20, 'covered' => false],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => true],
    ]));

    expect($lcov)
        ->toContain('SF:app/A.php')
        ->toContain('SF:app/B.php')
        ->toContain('DA:10,1')
        ->toContain('DA:20,0')
        ->toContain('DA:5,1');

    // Verify record structure: each file block ends with end_of_record
    $records = array_filter(explode('end_of_record', $lcov), fn(string $s): bool => trim($s) !== '');
    expect($records)->toHaveCount(2);
});

it('excludes operations with no source file or line', function (): void {
    $lcov = renderLcov(buildLcovCoverage([
        ['file' => 'app/A.php', 'line' => 10, 'covered' => true],
        ['file' => null, 'line' => 7, 'covered' => true],
        ['file' => 'app/C.php', 'line' => null, 'covered' => true],
    ]));

    expect($lcov)
        ->toContain('SF:app/A.php')
        ->not->toContain('SF:app/C.php');

    // The null-file operation should not produce any SF line for it
    expect(substr_count($lcov, 'SF:'))->toBe(1);
});

it('marks a shared line uncovered when any operation on it is uncovered (conservative)', function (): void {
    $lcov = renderLcov(buildLcovCoverage([
        ['file' => 'app/B.php', 'line' => 5, 'covered' => true],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => false],
    ]));

    expect($lcov)
        ->toContain('DA:5,0')
        ->not->toContain('DA:5,1');
});

it('computes LH (lines hit) and LF (lines found) per file', function (): void {
    $lcov = renderLcov(buildLcovCoverage([
        ['file' => 'app/A.php', 'line' => 10, 'covered' => true],
        ['file' => 'app/A.php', 'line' => 20, 'covered' => false],
        ['file' => 'app/A.php', 'line' => 30, 'covered' => true],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => true],
    ]));

    // Extract the record for app/A.php: 3 lines found, 2 hit
    $records = array_filter(explode('end_of_record', $lcov), fn(string $s): bool => trim($s) !== '');

    $aRecord = '';

    foreach ($records as $record) {
        if (str_contains($record, 'SF:app/A.php')) {
            $aRecord = $record;

            break;
        }
    }

    expect($aRecord)
        ->toContain('LH:2')
        ->toContain('LF:3');

    // Extract the record for app/B.php: 1 line found, 1 hit
    $bRecord = '';

    foreach ($records as $record) {
        if (str_contains($record, 'SF:app/B.php')) {
            $bRecord = $record;

            break;
        }
    }

    expect($bRecord)
        ->toContain('LH:1')
        ->toContain('LF:1');
});

it('emits a valid empty output when there is nothing to attribute', function (): void {
    $lcov = renderLcov(buildLcovCoverage([]));

    expect(trim($lcov))->toBe('');
});

it('handles null coverage gracefully', function (): void {
    $output = new BufferedOutput();
    $result = new LintResult(findings: [], level: 1, exitCode: 0, coverage: null);

    new LcovFormatter()->render($result, $output);

    expect(trim($output->fetch()))->toBe('');
});
