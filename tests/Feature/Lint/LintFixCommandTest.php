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
