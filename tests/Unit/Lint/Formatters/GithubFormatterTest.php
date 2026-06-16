<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Formatters\GithubFormatter;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('renders a ::error workflow command per level-0 finding', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        new LintResult(findings: [new Finding(
            ruleId: 'response.empty',
            severity: Severity::Broken,
            message: 'msg',
            location: new FindingLocation(file: 'F.php', line: 10),
            fixHint: 'fix it',
        )], level: 0, exitCode: 1),
        $output,
    );

    expect($output->fetch())->toContain('::error file=F.php,line=10,title=response.empty::msg. Fix: fix it.');
});

it('renders ::warning for level-1 findings', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        new LintResult(findings: [new Finding(
            ruleId: 'response.heuristic',
            severity: Severity::Degraded,
            message: 'heuristic used',
            location: new FindingLocation(file: 'G.php', line: 5),
        )], level: 1, exitCode: 0),
        $output,
    );

    expect($output->fetch())->toContain('::warning file=G.php,line=5,title=response.heuristic::heuristic used');
});

it('percent-encodes newlines in the body', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        new LintResult(findings: [new Finding(
            ruleId: 'test.rule',
            severity: Severity::Broken,
            message: "line one\nline two\r\nline three",
            location: new FindingLocation(),
        )], level: 0, exitCode: 1),
        $output,
    );

    $line = trim($output->fetch());
    expect($line)->toContain('::error title=test.rule::line one%0Aline two%0D%0Aline three');
});

it('percent-encodes percent signs in the body', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        new LintResult(findings: [new Finding(
            ruleId: 'test.rule',
            severity: Severity::Broken,
            message: '100% complete',
            location: new FindingLocation(),
        )], level: 0, exitCode: 1),
        $output,
    );

    expect(trim($output->fetch()))->toContain('100%25 complete');
});

it('percent-encodes commas and colons in property values', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        new LintResult(findings: [new Finding(
            ruleId: 'a:b,c',
            severity: Severity::Broken,
            message: 'msg',
            location: new FindingLocation(file: 'path/to:file,name.php'),
        )], level: 0, exitCode: 1),
        $output,
    );

    $line = trim($output->fetch());
    // file value: colons and commas encoded; title value: colons and commas encoded
    expect($line)
        ->toContain('file=path/to%3Afile%2Cname.php')
        ->toContain('title=a%3Ab%2Cc');
});

it('renders a coverage notice annotation when coverage is present', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
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

    $line = trim($output->fetch());
    expect($line)->toBe('::notice title=OpenAPI coverage::80.00% (8/10 operations)');
});

it('appends unattributed count to the coverage notice when non-zero', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
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

    $line = trim($output->fetch());
    expect($line)->toContain('80.00% (4/5 operations), 3 unattributed');
});

it('omits the coverage notice when coverage is null', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        new LintResult(findings: [], level: 2, exitCode: 0),
        $output,
    );

    expect(trim($output->fetch()))->toBe('');
});
