<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Formatters\CliFormatter;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('does not print spec headers when all findings belong to one spec', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [
            new Finding(ruleId: 'rule.a', severity: Severity::Broken, message: 'msg', spec: 'default'),
            new Finding(ruleId: 'rule.b', severity: Severity::Degraded, message: 'msg', spec: 'default'),
        ], level: 0, exitCode: 1),
        $output,
    );

    expect($output->fetch())->not->toContain('── spec:');
});

it('does not print spec headers when no findings have a spec set', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [
            new Finding(ruleId: 'rule.a', severity: Severity::Broken, message: 'msg'),
            new Finding(ruleId: 'rule.b', severity: Severity::Degraded, message: 'msg'),
        ], level: 0, exitCode: 1),
        $output,
    );

    expect($output->fetch())->not->toContain('── spec:');
});

it('groups findings by spec with a header per group', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [
            new Finding(ruleId: 'rule.a', severity: Severity::Broken, message: 'msg a', spec: 'default'),
            new Finding(ruleId: 'rule.b', severity: Severity::Broken, message: 'msg b', spec: 'v1'),
        ], level: 0, exitCode: 1),
        $output,
    );

    $text = $output->fetch();
    expect($text)
        ->toContain('── spec: default ──')
        ->toContain('── spec: v1 ──');
});

it('renders pre-build findings under a "configuration" header when mixed with spec findings', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [
            new Finding(ruleId: 'rule.a', severity: Severity::Broken, message: 'msg a'),
            new Finding(ruleId: 'rule.b', severity: Severity::Broken, message: 'msg b', spec: 'default'),
        ], level: 0, exitCode: 1),
        $output,
    );

    $text = $output->fetch();
    expect($text)
        ->toContain('── configuration ──')
        ->toContain('── spec: default ──');
});

it('does not print headers when only pre-build findings are present', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [
            new Finding(ruleId: 'rule.a', severity: Severity::Broken, message: 'msg a'),
        ], level: 0, exitCode: 1),
        $output,
    );

    $text = $output->fetch();
    expect($text)
        ->not->toContain('── configuration ──')
        ->not->toContain('── spec:');
});

it('renders finding rule ids inside the correct spec group', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [
            new Finding(ruleId: 'rule.alpha', severity: Severity::Broken, message: 'msg', spec: 'default'),
            new Finding(ruleId: 'rule.beta', severity: Severity::Broken, message: 'msg', spec: 'v1'),
        ], level: 0, exitCode: 1),
        $output,
    );

    $text = $output->fetch();
    $defaultPos = strpos($text, '── spec: default ──');
    $v1Pos      = strpos($text, '── spec: v1 ──');
    $alphaPos   = strpos($text, 'rule.alpha');
    $betaPos    = strpos($text, 'rule.beta');

    expect($defaultPos)->not->toBeFalse()
        ->and($v1Pos)->not->toBeFalse()
        ->and($alphaPos)->toBeGreaterThan($defaultPos)
        ->and($alphaPos)->toBeLessThan($v1Pos)
        ->and($betaPos)->toBeGreaterThan($v1Pos);
});

it('renders a coverage summary line when coverage is present', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(
            findings: [],
            level: 2,
            exitCode: 0,
            coverage: new CoverageSummary(
                generatorVersion: '1.0.0',
                level: 2,
                totalOperations: 10,
                coveredOperations: 7,
                coveragePercent: 70.00,
                unattributedFindings: 0,
                perTag: [],
            ),
        ),
        $output,
    );

    $text = $output->fetch();
    expect($text)->toContain('Coverage: 70.00%')
        ->and($text)->toContain('7/10 operations');
});

it('includes unattributed count in coverage line when non-zero', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(
            findings: [],
            level: 2,
            exitCode: 0,
            coverage: new CoverageSummary(
                generatorVersion: '1.0.0',
                level: 2,
                totalOperations: 5,
                coveredOperations: 5,
                coveragePercent: 100.00,
                unattributedFindings: 2,
                perTag: [],
            ),
        ),
        $output,
    );

    $text = $output->fetch();
    expect($text)->toContain('2 unattributed findings');
});

it('omits the coverage line when coverage is null', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        new LintResult(findings: [], level: 2, exitCode: 0),
        $output,
    );

    expect($output->fetch())->not->toContain('Coverage:');
});
