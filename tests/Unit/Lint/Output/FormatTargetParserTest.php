<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\LinterOutputFormat;
use Radiergummi\OpenApi\Lint\Output\FormatTargetParser;
use Radiergummi\OpenApi\Lint\Output\OutputChannel;

uses()->group('openapi', 'lint');

it('parses a bare format as that format writing to stdout', function (): void {
    $targets = new FormatTargetParser()->parse(['github']);

    expect($targets)->toHaveCount(1)
        ->and($targets[0]->format)->toBe(LinterOutputFormat::GitHub)
        ->and($targets[0]->target->channel)->toBe(OutputChannel::Stdout)
        ->and($targets[0]->target->path)->toBeNull();
});

it('treats stdout and stderr as reserved target keywords', function (): void {
    $targets = new FormatTargetParser()->parse(['cli:stdout', 'json:stderr']);

    expect($targets[0]->target->channel)->toBe(OutputChannel::Stdout)
        ->and($targets[1]->target->channel)->toBe(OutputChannel::Stderr)
        ->and($targets[1]->target->path)->toBeNull();
});

it('treats any other target as a file path', function (): void {
    $targets = new FormatTargetParser()->parse(['cobertura:coverage.xml']);

    expect($targets[0]->format)->toBe(LinterOutputFormat::Cobertura)
        ->and($targets[0]->target->channel)->toBe(OutputChannel::File)
        ->and($targets[0]->target->path)->toBe('coverage.xml');
});

it('splits on the first colon only so Windows paths survive', function (): void {
    $targets = new FormatTargetParser()->parse(['cobertura:C:\\out\\cov.xml']);

    expect($targets[0]->target->channel)->toBe(OutputChannel::File)
        ->and($targets[0]->target->path)->toBe('C:\\out\\cov.xml');
});

it('allows one stdout and one stderr and many distinct files in one run', function (): void {
    $targets = new FormatTargetParser()->parse([
        'github',
        'cli:stderr',
        'cobertura:coverage.xml',
        'json:lint.json',
    ]);

    expect($targets)->toHaveCount(4);
});

it('rejects an unknown format', function (): void {
    new FormatTargetParser()->parse(['nope:out.txt']);
})->throws(InvalidArgumentException::class, 'Invalid format: nope');

it('rejects two formats writing to stdout', function (): void {
    new FormatTargetParser()->parse(['github', 'cli']);
})->throws(InvalidArgumentException::class, 'stdout');

it('rejects two formats writing to the same file', function (): void {
    new FormatTargetParser()->parse(['cobertura:cov.xml', 'json:cov.xml']);
})->throws(InvalidArgumentException::class, 'cov.xml');
