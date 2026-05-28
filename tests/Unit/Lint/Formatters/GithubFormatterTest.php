<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Formatters\GithubFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('renders a ::error workflow command per level-0 finding', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        findings: [new Finding(
            ruleId: 'response.empty',
            level: 0,
            message: 'msg',
            location: new FindingLocation(file: 'F.php', line: 10),
            fixHint: 'fix it',
        )],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    expect($output->fetch())->toContain('::error file=F.php,line=10,title=response.empty::msg. Fix: fix it.');
});

it('renders ::warning for level-1 findings', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        findings: [new Finding(
            ruleId: 'response.heuristic',
            level: 1,
            message: 'heuristic used',
            location: new FindingLocation(file: 'G.php', line: 5),
        )],
        level: 1,
        exitCode: 0,
        output: $output,
    );

    expect($output->fetch())->toContain('::warning file=G.php,line=5,title=response.heuristic::heuristic used');
});

it('percent-encodes newlines in the body', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        findings: [new Finding(
            ruleId: 'test.rule',
            level: 0,
            message: "line one\nline two\r\nline three",
            location: new FindingLocation(),
        )],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    $line = trim($output->fetch());
    expect($line)->toContain('::error title=test.rule::line one%0Aline two%0D%0Aline three');
});

it('percent-encodes percent signs in the body', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        findings: [new Finding(
            ruleId: 'test.rule',
            level: 0,
            message: '100% complete',
            location: new FindingLocation(),
        )],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    expect(trim($output->fetch()))->toContain('100%25 complete');
});

it('percent-encodes commas and colons in property values', function (): void {
    $output = new BufferedOutput();
    new GithubFormatter()->render(
        findings: [new Finding(
            ruleId: 'a:b,c',
            level: 0,
            message: 'msg',
            location: new FindingLocation(file: 'path/to:file,name.php'),
        )],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    $line = trim($output->fetch());
    // file value: colons and commas encoded; title value: colons and commas encoded
    expect($line)
        ->toContain('file=path/to%3Afile%2Cname.php')
        ->toContain('title=a%3Ab%2Cc');
});
