<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Output\OutputChannel;
use Radiergummi\OpenApi\Lint\Output\OutputTarget;

uses()->group('openapi', 'lint');

it('resolves null token as stdout', function (): void {
    $target = OutputTarget::fromToken(null);

    expect($target->channel)->toBe(OutputChannel::Stdout)
        ->and($target->path)->toBeNull();
});

it('resolves empty string token as stdout', function (): void {
    $target = OutputTarget::fromToken('');

    expect($target->channel)->toBe(OutputChannel::Stdout)
        ->and($target->path)->toBeNull();
});

it('resolves the literal "stdout" token as stdout', function (): void {
    $target = OutputTarget::fromToken('stdout');

    expect($target->channel)->toBe(OutputChannel::Stdout)
        ->and($target->path)->toBeNull();
});

it('resolves the literal "stderr" token as stderr', function (): void {
    $target = OutputTarget::fromToken('stderr');

    expect($target->channel)->toBe(OutputChannel::Stderr)
        ->and($target->path)->toBeNull();
});

it('resolves any other token as a file path', function (): void {
    $target = OutputTarget::fromToken('/tmp/output.json');

    expect($target->channel)->toBe(OutputChannel::File)
        ->and($target->path)->toBe('/tmp/output.json');
});

it('labels a stdout target as "stdout"', function (): void {
    expect(OutputTarget::fromToken(null)->label())->toBe('stdout');
});

it('labels a stderr target as "stderr"', function (): void {
    expect(OutputTarget::fromToken('stderr')->label())->toBe('stderr');
});

it('labels a file target with its path', function (): void {
    expect(OutputTarget::fromToken('coverage.xml')->label())->toBe('coverage.xml');
});
