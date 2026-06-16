<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Formatters\JsonFormatter;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('renders findings as schema-versioned JSON', function (): void {
    $output = new BufferedOutput();

    new JsonFormatter()->render(
        new LintResult(findings: [
            new Finding(
                ruleId: 'response.empty',
                severity: Severity::Broken,
                message: 'No schema',
                location: new FindingLocation(file: 'F.php', line: 10, routeName: 'foo'),
                fixHint: 'Add #[Response].',
            ),
        ], level: 0, exitCode: 1),
        $output,
    );

    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['schema_version'])->toBe('1')
        ->and($decoded['level'])->toBe(0)
        ->and($decoded['exit_code'])->toBe(1)
        ->and($decoded['findings'])->toHaveCount(1)
        ->and($decoded['findings'][0]['rule_id'])->toBe('response.empty')
        ->and($decoded['summary']['total'])->toBe(1)
        ->and($decoded['summary']['by_level']['0'])->toBe(1);
});

it('renders empty findings', function (): void {
    $output = new BufferedOutput();
    new JsonFormatter()->render(new LintResult(findings: [], level: 2, exitCode: 0), $output);

    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['findings'])->toBe([])
        ->and($decoded['summary']['total'])->toBe(0);
});

it('includes a coverage block when coverage is present', function (): void {
    $output = new BufferedOutput();
    new JsonFormatter()->render(
        new LintResult(
            findings: [],
            level: 2,
            exitCode: 0,
            coverage: new CoverageSummary(
                generatorVersion: '2.0.0',
                level: 2,
                totalOperations: 6,
                coveredOperations: 5,
                coveragePercent: 83.33,
                unattributedFindings: 1,
                perTag: [['tag' => 'Users', 'total' => 3, 'covered' => 2, 'percent' => 66.67]],
            ),
        ),
        $output,
    );

    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKey('coverage')
        ->and($decoded['coverage']['generator_version'])->toBe('2.0.0')
        ->and($decoded['coverage']['total_operations'])->toBe(6)
        ->and($decoded['coverage']['covered_operations'])->toBe(5)
        ->and($decoded['coverage']['coverage_percent'])->toBe(83.33)
        ->and($decoded['coverage']['unattributed_findings'])->toBe(1)
        ->and($decoded['coverage']['per_tag'])->toHaveCount(1)
        ->and($decoded['coverage']['per_tag'][0]['tag'])->toBe('Users');
});

it('omits the coverage block when coverage is null', function (): void {
    $output = new BufferedOutput();
    new JsonFormatter()->render(
        new LintResult(findings: [], level: 2, exitCode: 0),
        $output,
    );

    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->not->toHaveKey('coverage');
});
