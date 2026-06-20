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
            'schema_version', 'mode', 'applied', 'skipped', 'withheld_destructive', 'modified_files', 'remaining', 'exit_code',
        ])
            ->and($decoded)->not->toHaveKey('findings')
            ->and($decoded['mode'])->toBe('check')
            ->and($decoded['applied'])->toBe(1)
            ->and($decoded['skipped'])->toBe([])
            ->and($decoded['withheld_destructive'])->toBe(0)
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

it('treats a bare --check as the safe level (no error, fix-mode runs)', function (): void {
    // VALUE_OPTIONAL regression guard: a bare flag resolves to the default 'safe' and must not error.
    $this->artisan('openapi:lint', [
        '--check' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])
        ->expectsOutputToContain('- openapi:lint --fix: 1 pending')
        ->assertExitCode(1);
});

it('does not enter fix-mode when neither --fix nor --check is present', function (): void {
    // The OPTIONAL default ('safe') must not be mistaken for the flag being set: a plain lint runs
    // (the finding is reported normally, exit 1), with no fix-run summary line emitted.
    $this->artisan('openapi:lint', [
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])
        ->doesntExpectOutputToContain('openapi:lint --fix:')
        ->assertExitCode(1);
});

it('accepts --check=dangerous without error', function (): void {
    $this->artisan('openapi:lint', [
        '--check' => 'dangerous',
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])
        ->expectsOutputToContain('- openapi:lint --fix: 1 pending')
        ->assertExitCode(1);
});

it('--check --show-diff prints a unified diff of the pending fix and writes nothing', function (): void {
    $file = fixCommandFixtureFile();
    $before = file_get_contents($file);

    $this->artisan('openapi:lint', [
        '--check' => true,
        '--show-diff' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])
        ->expectsOutputToContain('@@')
        ->expectsOutputToContain("-    #[Link(name: 'self', operationId: 'reports.show')]")
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before);
});

it('--show-diff is gated: without it no diff is printed', function (): void {
    $this->artisan('openapi:lint', [
        '--check' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])
        ->doesntExpectOutputToContain('@@')
        ->assertExitCode(1);
});

it('--show-diff leaves the exit code unchanged', function (): void {
    $this->artisan('openapi:lint', [
        '--check' => true,
        '--show-diff' => true,
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
        '--format' => 'markdown',
    ])->assertExitCode(1);
});

it('--show-diff leaves the frozen fix-run JSON envelope byte-identical', function (): void {
    $run = function (bool $showDiff): string {
        $path = tempnam(sys_get_temp_dir(), 'openapi-fixrun-') . '.json';

        try {
            $args = [
                '--check' => true,
                '--only' => 'link.duplicate-name',
                '--uri' => 'lint-fixtures/fix-link*',
                '--format' => "json:{$path}",
            ];

            if ($showDiff) {
                $args['--show-diff'] = true;
            }

            $this->artisan('openapi:lint', $args)->assertExitCode(1);

            return (string) file_get_contents($path);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    };

    expect($run(true))->toBe($run(false));
});

it('--show-diff combined with a real --fix warns it writes', function (): void {
    $file = fixCommandFixtureFile();
    $before = file_get_contents($file);

    try {
        $this->artisan('openapi:lint', [
            '--fix' => true,
            '--show-diff' => true,
            '--only' => 'link.duplicate-name',
            '--uri' => 'lint-fixtures/fix-link*',
            '--format' => 'markdown',
        ])
            ->expectsOutputToContain('--show-diff with --fix')
            ->assertExitCode(0);
    } finally {
        file_put_contents($file, $before);
    }
});

it('errors clearly on an unknown --check level', function (): void {
    // The artisan harness propagates the thrown InvalidArgumentException rather than converting it
    // to an exit code, so assert on the throw directly (mirrors the --min-coverage validation test).
    expect(fn(): int => $this->artisan('openapi:lint', [
        '--check' => 'bogus',
        '--only' => 'link.duplicate-name',
        '--uri' => 'lint-fixtures/fix-link*',
    ])->run())->toThrow(InvalidArgumentException::class, "Unknown --check level 'bogus'. Expected 'safe' or 'dangerous'.");
});
