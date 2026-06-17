<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\FixCommandLinkController;

uses()->group('openapi', 'lint', 'fix');

beforeEach(function (): void {
    Route::get('lint-fixtures/fix-link', [FixCommandLinkController::class, 'index'])->name('lint.fix.link');
});

function fixCommandFixtureFile(): string
{
    return new ReflectionClass(FixCommandLinkController::class)->getFileName() ?: '';
}

it('--check exits 1 on a pending fix and writes nothing', function (): void {
    $file = fixCommandFixtureFile();
    $before = file_get_contents($file);

    $this->artisan('openapi:lint', [
        '--check' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'json',
    ])->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before);
});

it('--fix removes the duplicate attribute and exits 0', function (): void {
    $file = fixCommandFixtureFile();
    $before = file_get_contents($file);

    try {
        $this->artisan('openapi:lint', [
            '--fix' => true,
            '--only' => 'link.duplicate-name',
            '--uri' => 'lint-fixtures/fix-link*',
            '--format' => 'json',
        ])->assertExitCode(0);

        $after = file_get_contents($file);

        expect($after)->not->toBe($before)
            ->and(substr_count((string) $after, "#[Link(name: 'self', operationId: 'reports.show')]"))->toBe(1);
    } finally {
        file_put_contents($file, $before);
    }
});

it('--check --format=json emits the frozen fix-run envelope, not the lint findings shape', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'openapi-fixrun-') . '.json';

    try {
        $this->artisan('openapi:lint', [
            '--check' => true,
            '--only' => 'link.duplicate-name',
            '--uri' => 'lint-fixtures/fix-link*',
            '--format' => "json:{$path}",
        ])->assertExitCode(1);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($decoded))->toBe([
            'schema_version', 'mode', 'applied', 'skipped', 'modified_files', 'remaining', 'exit_code',
        ])
            ->and($decoded)->not->toHaveKey('findings')
            ->and($decoded['mode'])->toBe('check')
            ->and($decoded['applied'])->toBe(1)
            ->and($decoded['skipped'])->toBe([])
            ->and($decoded['exit_code'])->toBe(1);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('--check --format=github surfaces the fix-run summary as a workflow notice', function (): void {
    $this->artisan('openapi:lint', [
        '--check' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'github',
    ])
        ->expectsOutputToContain('::notice title=OpenAPI fix::openapi:lint --fix: 1 pending')
        ->assertExitCode(1);
});

it('--check --format=markdown surfaces the fix-run summary as a bullet', function (): void {
    $this->artisan('openapi:lint', [
        '--check' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])
        ->expectsOutputToContain('- openapi:lint --fix: 1 pending')
        ->assertExitCode(1);
});
