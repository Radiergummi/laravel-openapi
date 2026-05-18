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

it('renders findings grouped by file', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
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
                    file: 'F.php',
                    line: 5,
                    routeMethod: 'POST',
                    routeUri: '/bar',
                ),
            ),
            new Finding(
                'schema.missing',
                0,
                'Missing schema',
                new FindingLocation(
                    file: 'G.php',
                    line: 3,
                    routeMethod: 'GET',
                    routeUri: '/baz',
                ),
            ),
        ],
        level: 1,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();
    expect($text)
        ->toContain('F.php')
        ->and($text)
        ->toContain('G.php')
        ->and($text)
        ->toContain('response.empty')
        ->and($text)
        ->toContain('response.heuristic')
        ->and($text)
        ->toContain('No schema');
});

it('renders findings without a source location first', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [
            new Finding(
                'response.empty',
                0,
                'No schema',
                new FindingLocation(
                    file: 'Z.php',
                    line: 10,
                    routeMethod: 'GET',
                    routeUri: '/foo',
                ),
            ),
            new Finding(
                'spec.missing',
                0,
                'No spec file',
                new FindingLocation(),
            ),
        ],
        level: 1,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();
    $noLocationPos = strpos($text, '(no source location)');
    $filePos = strpos($text, 'Z.php');

    expect($noLocationPos)
        ->not->toBeFalse()
        ->and($filePos)
        ->not->toBeFalse()
        ->and($noLocationPos)
        ->toBeLessThan($filePos);
});

it('renders file groups as trees with connector characters', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
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
                    file: 'F.php',
                    line: 5,
                    routeMethod: 'POST',
                    routeUri: '/bar',
                ),
                fixHint: 'Add a response schema',
            ),
        ],
        level: 1,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();

    // Tree connectors from TreeStyle::rounded()
    expect($text)
        ->toContain('├─')
        ->and($text)
        ->toContain('╰─')
        ->and($text)
        ->toContain('Fix:');
});

it('renders a clean summary when no findings', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [],
        level: 0,
        exitCode: 0,
        output: $output,
    );

    $text = $output->fetch();
    expect($text)->toContain('0 total');
});
