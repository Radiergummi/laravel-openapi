<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\Formatters\CliFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('renders findings grouped by severity', function (): void {
    $output = new BufferedOutput();
    new CliFormatter(basePath: '/')->render(
        findings: [
            new Finding(
                'response.empty',
                0,
                'No schema',
                new FindingLocation(
                    file: 'F.php',
                    line: 10,
                    routeMethod: 'GET',
                    routeUri: '/foo',
                ),
            ),
            new Finding(
                'response.heuristic',
                1,
                'Heuristic used',
                new FindingLocation(
                    file: 'G.php',
                    line: 5,
                    routeMethod: 'POST',
                    routeUri: '/bar',
                ),
            ),
        ],
        level: 1,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();
    expect($text)
        ->toContain('response.empty')
        ->and($text)
        ->toContain('response.heuristic')
        ->and($text)
        ->toContain('No schema');
});

it('renders a clean summary when no findings', function (): void {
    $output = new BufferedOutput();
    new CliFormatter(basePath: '/')->render(
        findings: [],
        level: 0,
        exitCode: 0,
        output: $output,
    );

    $text = $output->fetch();
    expect($text)->toContain('0 total');
});

it('includes line number 0 in the file link', function (): void {
    $output = new BufferedOutput();
    new CliFormatter(basePath: '/app/')->render(
        findings: [
            new Finding(
                'test.rule',
                0,
                'Zero-line finding',
                new FindingLocation(file: '/app/Foo.php', line: 0),
            ),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    // Line 0 is a valid line number and must appear in the output
    expect($output->fetch())->toContain('Foo.php:0');
});
