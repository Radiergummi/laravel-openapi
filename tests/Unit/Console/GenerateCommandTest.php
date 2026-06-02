<?php

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

it('succeeds and writes a valid empty-paths document when no routes are discoverable', function (): void {
    $path = generateCommandTmpPath();
    config([
        'openapi.output_path' => $path,
        // Drop the only fixture route so the discoverable set is empty.
        'openapi.filters' => [
            new class () implements Radiergummi\OpenApi\Contracts\Routing\RouteFilter {
                public function shouldSkip(Illuminate\Routing\Route $route): bool
                {
                    return true;
                }
            },
        ],
    ]);
    app()->forgetScopedInstances();

    $this->artisan('openapi:generate')->assertExitCode(Command::SUCCESS);

    $document = Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($path));

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['paths'] ?? 'missing')->toBe([]);

    @unlink($path);
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

it('--explain exits successfully', function (): void {
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

    // Trace lines are written to stderr; Artisan's PendingCommand only captures
    // stdout, so we assert the command succeeds rather than checking output content.
    $this->artisan('openapi:generate', ['--explain' => true])
        ->assertSuccessful();

    @unlink($defaultPath);
    @unlink($v1Path);
});

it('--explain writes traces to stderr, not stdout, so --output=- stays parseable', function (): void {
    config(['openapi.output_path' => generateCommandTmpPath()]);

    // Artisan's PendingCommand captures only stdout. If --explain wrote to stdout,
    // the trace lines would appear in the captured output. After the fix they go
    // to stderr, so doesntExpectOutputToContain passes cleanly.
    $this->artisan('openapi:generate', ['--output' => '-', '--explain' => true])
        ->doesntExpectOutputToContain('[default]')
        ->assertSuccessful();
});

it('rejects unsupported --format values with a clear error', function (): void {
    config(['openapi.output_path' => generateCommandTmpPath()]);

    $this->artisan('openapi:generate', ['--format' => 'xml'])
        ->expectsOutputToContain("Unsupported --format value 'xml'")
        ->assertExitCode(Command::FAILURE);
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
