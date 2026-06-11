<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Tests\Fixtures\Lint\BrokenController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    Route::get('lint-fixtures/clean', [CleanController::class, 'list'])->name('lint.clean.list');
    Route::get('lint-fixtures/broken/stream', [BrokenController::class, 'stream'])->name('lint.broken.stream');
});

it('writes a Cobertura report to a file in the same run that lints findings', function (): void {
    $path = sys_get_temp_dir() . '/openapi-cov-' . uniqid() . '.xml';

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/*',
        '--format' => ['github', 'cobertura:' . $path],
    ])->assertExitCode(1); // the broken controller has findings

    expect(file_exists($path))->toBeTrue();

    $xml = new SimpleXMLElement((string) file_get_contents($path));
    $classes = $xml->xpath('//class') ?: [];

    $filenames = array_map(static fn(SimpleXMLElement $c): string => (string) $c['filename'], $classes);
    expect(implode(' ', $filenames))
        ->toContain('BrokenController.php')
        ->toContain('CleanController.php');

    // The broken operation is uncovered → its line is a miss.
    $brokenHits = [];

    foreach ($classes as $class) {
        if (str_contains((string) $class['filename'], 'BrokenController.php')) {
            foreach ($class->lines->line as $line) {
                $brokenHits[] = (int) $line['hits'];
            }
        }
    }

    expect($brokenHits)->toContain(0);

    unlink($path);
});

it('writes a bare cobertura format to stdout when no target is given', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => ['cobertura'],
    ])
        ->expectsOutputToContain('<coverage')
        ->assertExitCode(0);
});

it('writes a JSON report to a file target', function (): void {
    $path = sys_get_temp_dir() . '/openapi-lint-' . uniqid() . '.json';

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--uri' => 'lint-fixtures/*',
        '--format' => ['json:' . $path],
    ])->assertExitCode(1);

    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKey('schema_version')
        ->and($decoded)->toHaveKey('findings')
        ->and($decoded)->toHaveKey('coverage')
        ->and($decoded['findings'])->not->toBeEmpty();

    unlink($path);
});

it('can write findings to stderr while coverage goes to a file', function (): void {
    $path = sys_get_temp_dir() . '/openapi-cov-stderr-' . uniqid() . '.xml';

    $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => ['github:stderr', 'cobertura:' . $path],
    ])->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $xml = new SimpleXMLElement((string) file_get_contents($path));
    expect($xml->getName())->toBe('coverage');

    unlink($path);
});

it('rejects two formats targeting stdout via the artisan command', function (): void {
    expect(fn(): int => $this->artisan('openapi:lint', [
        '--level' => 0,
        '--uri' => 'lint-fixtures/clean*',
        '--format' => ['github', 'json'],
    ])->run())->toThrow(InvalidArgumentException::class, 'stdout');
});
