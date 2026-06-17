<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Formatters\MarkdownFormatter;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('renders a GFM findings table with one row per finding', function (): void {
    $output = new BufferedOutput();
    new MarkdownFormatter()->render(
        new LintResult(
            findings: [
                new Finding(
                    ruleId: 'response.empty',
                    severity: Severity::Broken,
                    message: 'no response',
                    location: new FindingLocation(file: 'app/Http/F.php', line: 10),
                    fixHint: 'add one',
                    spec: 'public',
                ),
                new Finding(
                    ruleId: 'operation.id-missing',
                    severity: Severity::Underspecified,
                    message: 'missing id',
                    location: new FindingLocation(
                        routeMethod: HttpMethod::Get,
                        routeUri: 'users/{id}',
                    ),
                ),
            ],
            level: 4,
            exitCode: 1,
        ),
        $output,
    );

    $rendered = $output->fetch();

    expect($rendered)
        ->toContain('| Severity | Rule | Location | Message |')
        ->toContain('| --- | --- | --- | --- |')
        ->toContain('`response.empty`')
        ->toContain('`app/Http/F.php:10`')
        ->toContain('[spec:public] no response. Fix: add one.')
        ->toContain('`operation.id-missing`')
        ->toContain('GET users/{id}')
        ->toContain('missing id');
});

it('escapes pipe characters in cell text so the table stays intact', function (): void {
    $output = new BufferedOutput();
    new MarkdownFormatter()->render(
        new LintResult(
            findings: [new Finding(
                ruleId: 'test.rule',
                severity: Severity::Broken,
                message: 'value a | value b',
                location: new FindingLocation(),
            )],
            level: 0,
            exitCode: 1,
        ),
        $output,
    );

    expect($output->fetch())
        ->toContain('value a \| value b')
        ->not->toContain('value a | value b');
});

it('renders a coverage summary line when coverage is present', function (): void {
    $output = new BufferedOutput();
    new MarkdownFormatter()->render(
        new LintResult(
            findings: [],
            level: 2,
            exitCode: 0,
            coverage: new CoverageSummary(
                generatorVersion: '1.0.0',
                level: 2,
                totalOperations: 10,
                coveredOperations: 8,
                coveragePercent: 80.00,
                unattributedFindings: 0,
                perTag: [],
            ),
        ),
        $output,
    );

    expect($output->fetch())
        ->toContain('Coverage: **80.00%** (8/10 operations)')
        ->toContain('No findings.');
});

it('appends the unattributed count to the coverage line when non-zero', function (): void {
    $output = new BufferedOutput();
    new MarkdownFormatter()->render(
        new LintResult(
            findings: [],
            level: 2,
            exitCode: 0,
            coverage: new CoverageSummary(
                generatorVersion: '1.0.0',
                level: 2,
                totalOperations: 5,
                coveredOperations: 4,
                coveragePercent: 80.00,
                unattributedFindings: 3,
                perTag: [],
            ),
        ),
        $output,
    );

    expect($output->fetch())->toContain('3 unattributed');
});

it('renders "No findings." and no table when there are no findings', function (): void {
    $output = new BufferedOutput();
    new MarkdownFormatter()->render(
        new LintResult(findings: [], level: 4, exitCode: 0),
        $output,
    );

    expect($output->fetch())
        ->toContain('No findings.')
        ->not->toContain('| Severity |');
});
