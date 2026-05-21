<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;

uses()->group('openapi');

function clearCommandTmpPath(string $suffix = 'yaml'): string
{
    return sys_get_temp_dir() . '/laravel-openapi-clear-' . uniqid('', true) . '.' . $suffix;
}

it('removes the configured output file when it exists', function (): void {
    $path = clearCommandTmpPath();
    file_put_contents($path, "openapi: 3.1.0\n");
    config(['openapi.output_path' => $path]);

    expect(file_exists($path))->toBeTrue();

    $this->artisan('openapi:clear')
        ->expectsOutputToContain('OpenAPI specification cleared.')
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($path))->toBeFalse();
});

it('exits successfully when the configured file does not exist', function (): void {
    $path = clearCommandTmpPath();
    config(['openapi.output_path' => $path]);

    expect(file_exists($path))->toBeFalse();

    $this->artisan('openapi:clear')
        ->expectsOutputToContain('OpenAPI specification cleared.')
        ->assertExitCode(Command::SUCCESS);
});

it('removes the file passed as a path argument, overriding the config default', function (): void {
    $configPath = clearCommandTmpPath();
    $argPath = clearCommandTmpPath();
    file_put_contents($configPath, 'config');
    file_put_contents($argPath, 'arg');
    config(['openapi.output_path' => $configPath]);

    $this->artisan('openapi:clear', ['path' => $argPath])
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($argPath))->toBeFalse()
        ->and(file_exists($configPath))->toBeTrue();

    @unlink($configPath);
});

it('fails when the path argument is "-" (stdout)', function (): void {
    $this->artisan('openapi:clear', ['path' => '-'])
        ->expectsOutputToContain('Cannot clear stdout output.')
        ->assertExitCode(Command::FAILURE);
});
