<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

function tmpSpecPath(): string
{
    return sys_get_temp_dir() . '/laravel-openapi-multispec-' . uniqid('', true) . '.yaml';
}

class MultiSpecV1Controller extends Controller
{
    /** List widgets. */
    public function index(): array
    {
        return ['ok' => true];
    }
}

class MultiSpecV2Controller extends Controller
{
    /** List gadgets. */
    public function index(): array
    {
        return ['ok' => true];
    }
}

/**
 * End-to-end guard for #49: drive the `openapi:generate` command across a multi-spec config and
 * assert every defined spec is written as its own parseable file whose `paths` carry only the
 * routes that belong to it. Unlike the unit-level resolution tests, this exercises the full path
 * through {@see \Radiergummi\OpenApi\Console\GenerateCommand}'s target loop and file writes.
 *
 * Two named specs partition cleanly by URL prefix: a route under `api/v2/*` can never match
 * `api/v1/*`, so neither named document can leak the other's routes. The implicit `default` spec
 * has no match config and is a catch-all, so it carries both — asserted here too so the third
 * generated file is documented rather than silently produced.
 */
it('generates every spec to its own file, each carrying only its own routes', function (): void {
    $defaultPath = tmpSpecPath();
    $v1Path = tmpSpecPath();
    $v2Path = tmpSpecPath();

    config([
        'openapi.output_path' => $defaultPath,
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*'], 'output_path' => $v1Path],
            'v2' => ['match' => ['prefix' => 'api/v2/*'], 'output_path' => $v2Path],
        ],
    ]);
    // SpecRegistry is a scoped singleton that memoises on first resolution; drop it so the command
    // sees the multi-spec config set above rather than a cached single-spec result.
    app()->forgetScopedInstances();

    Route::get('api/v1/widgets', [MultiSpecV1Controller::class, 'index']);
    Route::get('api/v2/gadgets', [MultiSpecV2Controller::class, 'index']);

    foreach ([$defaultPath, $v1Path, $v2Path] as $path) {
        @unlink($path);
    }

    try {
        $this->artisan('openapi:generate')->assertExitCode(Command::SUCCESS);

        expect(file_exists($v1Path))->toBeTrue()
            ->and(file_exists($v2Path))->toBeTrue()
            ->and(file_exists($defaultPath))->toBeTrue();

        $v1 = Yaml::parse((string) file_get_contents($v1Path));
        $v2 = Yaml::parse((string) file_get_contents($v2Path));
        $default = Yaml::parse((string) file_get_contents($defaultPath));

        // Each named spec carries only its own route — neither leaks the other's.
        expect(array_keys($v1['paths']))->toBe(['/api/v1/widgets'])
            ->and(array_keys($v2['paths']))->toBe(['/api/v2/gadgets']);

        // The implicit default spec is a catch-all and carries both.
        expect(array_keys($default['paths']))
            ->toEqualCanonicalizing(['/api/v1/widgets', '/api/v2/gadgets']);
    } finally {
        foreach ([$defaultPath, $v1Path, $v2Path] as $path) {
            @unlink($path);
        }
    }
});
