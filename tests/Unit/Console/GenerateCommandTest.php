<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Symfony\Component\Console\Command\Command;

uses()->group('openapi');

function generateCommandTmpPath(string $suffix = 'yaml'): string
{
    return sys_get_temp_dir() . '/laravel-openapi-generate-' . uniqid('', true) . '.' . $suffix;
}

/**
 * Registers a document transformer that injects an items-less array schema — a shape swagger-php's
 * validation pass rejects — so a test can assert the pass fires (or is skipped).
 */
function corruptGeneratedDocument(): void
{
    OpenApiExtensions::transformDocument(static function (OA\OpenApi $document): void {
        $document->components = new OA\Components(['schemas' => [
            new OA\Schema(['schema' => 'CorruptArray', 'type' => 'object', 'properties' => [
                new OA\Property(['property' => 'tags', 'type' => 'array']),
            ]]),
        ]]);
    });
}

beforeEach(function (): void {
    Route::get('/generate-fixture', [CleanController::class, 'list'])->name('generate.fixture');
});

afterEach(function (): void {
    OpenApiExtensions::flush();
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

it('writes to stdout instead of a file and suppresses the info line when --output is "-"', function (): void {
    $path = generateCommandTmpPath();
    config(['openapi.output_path' => $path]);

    // Artisan's PendingCommand captures the Symfony console buffer, not the process STDOUT
    // handle that "-" writes to — so we assert the observable side effects instead: the
    // info line is suppressed and, crucially, nothing is written to the configured path.
    $this->artisan('openapi:generate', ['--output' => '-'])
        ->doesntExpectOutputToContain('written to')
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($path))->toBeFalse();
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
    // SpecRegistry is a scoped singleton that memoises on first resolution; drop it so the
    // command sees the two-spec config above rather than a cached single-spec result.
    app()->forgetScopedInstances();

    $this->artisan('openapi:generate', ['--output' => '/tmp/x.yaml'])
        ->expectsOutputToContain('--output= requires a single spec target')
        ->assertFailed();
});

it('runs the validation pass by default, rejecting an invalid document', function (): void {
    $path = generateCommandTmpPath();
    config(['openapi.output_path' => $path]);
    corruptGeneratedDocument();

    // swagger-php reports the items-less array through trigger_error(). The command collects those
    // warnings rather than letting the host's error handler escalate them to a fatal, then fails on
    // validate()'s own verdict.
    $this->artisan('openapi:generate')
        ->expectsOutputToContain('OpenAPI validation failed')
        ->assertExitCode(Command::FAILURE);

    expect(file_exists($path))->toBeFalse();

    @unlink($path);
});

it('skips the validation pass and writes the document when --no-validate is passed', function (): void {
    $path = generateCommandTmpPath();
    config(['openapi.output_path' => $path]);
    corruptGeneratedDocument();

    // With the pass skipped, the invalid document never reaches validate() — no warning, no failure.
    $this->artisan('openapi:generate', ['--no-validate' => true])
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

it('still writes a valid document when --no-validate is passed', function (): void {
    $path = generateCommandTmpPath();
    config(['openapi.output_path' => $path]);

    $this->artisan('openapi:generate', ['--no-validate' => true])
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toStartWith('openapi:');

    @unlink($path);
});

it('hints when an installed integration package has its plugin disabled', function (): void {
    // The stock plugin list leaves Fractal and QueryBuilder off, while both packages are installed
    // in the test environment, so the advisory should name them.
    config(['openapi.output_path' => generateCommandTmpPath()]);

    $this->artisan('openapi:generate')
        ->expectsOutputToContain('league/fractal')
        ->expectsOutputToContain('spatie/laravel-query-builder')
        ->assertExitCode(Command::SUCCESS);
});

it('does not hint for an integration whose plugin is enabled', function (): void {
    config([
        'openapi.output_path' => generateCommandTmpPath(),
        'openapi.plugins' => [Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin::class],
    ]);
    app()->forgetScopedInstances();

    $this->artisan('openapi:generate')
        ->doesntExpectOutputToContain('league/fractal')
        ->assertExitCode(Command::SUCCESS);
});

it('emits the plugin hint without polluting the stdout document under --output=-', function (): void {
    // The hint renders to the error stream, so it never lands in the document a real CLI run writes
    // to stdout. The PendingCommand harness wraps a single buffer, so the captured output carries
    // the advisory (proving it was emitted) while the canonical document, generated here to a file,
    // must stay valid YAML beginning with the OpenAPI marker and never contain the hint text.
    config(['openapi.output_path' => generateCommandTmpPath()]);
    $this->artisan('openapi:generate', ['--output' => '-'])
        ->expectsOutputToContain('league/fractal')
        ->assertExitCode(Command::SUCCESS);

    $documentPath = generateCommandTmpPath();
    config(['openapi.output_path' => $documentPath]);
    app()->forgetScopedInstances();
    $this->artisan('openapi:generate')->assertExitCode(Command::SUCCESS);

    expect(file_get_contents($documentPath))->toStartWith('openapi:')
        ->and(file_get_contents($documentPath))->not->toContain('league/fractal is installed');

    @unlink($documentPath);
});
