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
use Radiergummi\OpenApi\Core\Lint\Formatters\JsonFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('renders findings as schema-versioned JSON', function (): void {
    $output = new BufferedOutput();

    (new JsonFormatter())->render(
        findings: [
            new Finding(
                ruleId: 'response.empty',
                level: 0,
                message: 'No schema',
                location: new FindingLocation(file: 'F.php', line: 10, routeName: 'foo'),
                fixHint: 'Add #[Response].',
            ),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
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
    (new JsonFormatter())->render(findings: [], level: 2, exitCode: 0, output: $output);

    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['findings'])->toBe([])
        ->and($decoded['summary']['total'])->toBe(0);
});
