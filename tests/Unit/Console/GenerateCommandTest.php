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
        ->expectsOutputToContain("OpenAPI document for spec 'default' written to")
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toStartWith('openapi:');

    @unlink($path);
});

it('respects an explicit --output option over the configured default', function (): void {
    $configPath = generateCommandTmpPath();
    $argPath = generateCommandTmpPath();
    config(['openapi.output_path' => $configPath]);

    $this->artisan('openapi:generate', ['--output' => $argPath])
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

it('prints the document to stdout and suppresses the info line when --output is "-"', function (): void {
    $this->artisan('openapi:generate', ['--output' => '-'])
        ->doesntExpectOutputToContain('OpenAPI document written to')
        ->assertExitCode(Command::SUCCESS);
});

it('fails when the output directory does not exist', function (): void {
    $bogus = sys_get_temp_dir() . '/laravel-openapi-no-such-dir-' . uniqid('', true) . '/spec.yaml';

    $this->artisan('openapi:generate', ['--output' => $bogus])
        ->expectsOutputToContain('Output directory does not exist')
        ->assertExitCode(Command::FAILURE);
});

it('generates every defined spec by default, writing each to its output_path', function (): void {
    $defaultPath = generateCommandTmpPath();
    $v1Path = generateCommandTmpPath();

    config([
        'openapi.output_path' => $defaultPath,
        'openapi.specs' => [
            'v1' => [
                'match' => ['prefix' => 'api/v1/*'],
                'output_path' => $v1Path,
            ],
        ],
    ]);

    @unlink($defaultPath);
    @unlink($v1Path);

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(file_exists($defaultPath))->toBeTrue()
        ->and(file_exists($v1Path))->toBeTrue();

    @unlink($defaultPath);
    @unlink($v1Path);
});

it('generates only the named spec when passed positionally', function (): void {
    $defaultPath = generateCommandTmpPath();
    $v1Path = generateCommandTmpPath();

    config([
        'openapi.output_path' => $defaultPath,
        'openapi.specs' => [
            'v1' => [
                'match' => ['prefix' => 'api/v1/*'],
                'output_path' => $v1Path,
            ],
        ],
    ]);

    @unlink($defaultPath);
    @unlink($v1Path);

    $this->artisan('openapi:generate', ['spec' => 'v1'])->assertSuccessful();

    expect(file_exists($v1Path))->toBeTrue()
        ->and(file_exists($defaultPath))->toBeFalse();

    @unlink($v1Path);
});

it('--explain prints one decision line per (route × spec)', function (): void {
    $defaultPath = generateCommandTmpPath();
    $v1Path = generateCommandTmpPath();

    config([
        'openapi.output_path' => $defaultPath,
        'openapi.specs' => [
            'v1' => [
                'match' => ['prefix' => 'api/v1/*'],
                'output_path' => $v1Path,
            ],
        ],
    ]);

    $this->artisan('openapi:generate', ['--explain' => true])
        ->expectsOutputToContain('[default]')
        ->expectsOutputToContain('[v1]')
        ->assertSuccessful();

    @unlink($defaultPath);
    @unlink($v1Path);
});

it('--output= is rejected when generating multiple specs', function (): void {
    $v1Path = generateCommandTmpPath();

    config([
        'openapi.specs' => [
            'v1' => [
                'match' => ['prefix' => 'api/v1/*'],
                'output_path' => $v1Path,
            ],
        ],
    ]);

    $this->artisan('openapi:generate', ['--output' => '/tmp/x.yaml'])
        ->expectsOutputToContain('--output= requires a single spec target')
        ->assertFailed();
});
