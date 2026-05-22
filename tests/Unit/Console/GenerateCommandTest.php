<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Symfony\Component\Console\Command\Command;

uses()->group('openapi');

function generateCommandTmpPath(string $suffix = 'yaml'): string
{
    return sys_get_temp_dir() . '/laravel-openapi-generate-' . uniqid('', true) . '.' . $suffix;
}

beforeEach(function (): void {
    Route::get('/generate-fixture', [CleanController::class, 'list'])->name('generate.fixture');
});

it('writes the configured output path on success', function (): void {
    $path = generateCommandTmpPath();
    config(['openapi.output_path' => $path]);

    $this->artisan('openapi:generate')
        ->expectsOutputToContain('OpenAPI document written to')
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toStartWith('openapi:');

    @unlink($path);
});

it('respects an explicit path argument over the configured default', function (): void {
    $configPath = generateCommandTmpPath();
    $argPath = generateCommandTmpPath();
    config(['openapi.output_path' => $configPath]);

    $this->artisan('openapi:generate', ['path' => $argPath])
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($argPath))->toBeTrue()
        ->and(file_exists($configPath))->toBeFalse();

    @unlink($argPath);
});

it('writes JSON when --format=json is passed', function (): void {
    $path = generateCommandTmpPath('json');
    config(['openapi.output_path' => $path]);

    $this->artisan('openapi:generate', ['--format' => 'json'])
        ->assertExitCode(Command::SUCCESS);

    $content = file_get_contents($path);

    expect($content)->toStartWith('{');

    $decoded = json_decode($content, true);
    expect($decoded)->toBeArray()->toHaveKey('openapi');

    @unlink($path);
});

it('prints the document to stdout and suppresses the info line when path is "-"', function (): void {
    $this->artisan('openapi:generate', ['path' => '-'])
        ->doesntExpectOutputToContain('OpenAPI document written to')
        ->assertExitCode(Command::SUCCESS);
});

it('fails when the output directory does not exist', function (): void {
    $bogus = sys_get_temp_dir() . '/laravel-openapi-no-such-dir-' . uniqid('', true) . '/spec.yaml';

    $this->artisan('openapi:generate', ['path' => $bogus])
        ->expectsOutputToContain('Output directory does not exist')
        ->assertExitCode(Command::FAILURE);
});
