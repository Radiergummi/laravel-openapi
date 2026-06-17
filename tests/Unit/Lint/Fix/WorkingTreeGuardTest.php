<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\DirtyWorkingTreeException;
use Radiergummi\OpenApi\Lint\Fix\WorkingTreeGuard;
use Symfony\Component\Process\Process;

uses()->group('openapi', 'lint', 'fix');

function gitAvailable(): bool
{
    $process = new Process(['git', '--version']);

    try {
        $process->run();
    } catch (Throwable) {
        return false;
    }

    return $process->isSuccessful();
}

function makeTempGitRepo(): string
{
    $dir = sys_get_temp_dir() . '/openapi-wtg-' . uniqid();
    mkdir($dir);

    foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 't@t'], ['git', 'config', 'user.name', 't']] as $cmd) {
        new Process($cmd, $dir)->run();
    }

    return $dir;
}

function commit(string $dir): void
{
    new Process(['git', 'add', '-A'], $dir)->run();
    new Process(['git', 'commit', '-q', '-m', 'x'], $dir)->run();
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/openapi-wtg-*') ?: [] as $leftover) {
        new Process(['rm', '-rf', $leftover])->run();
    }
});

it('allows when the target file is clean and tracked', function (): void {
    $dir = makeTempGitRepo();
    file_put_contents("{$dir}/a.php", "<?php\n");
    commit($dir);

    expect(fn() => new WorkingTreeGuard()->assertClean(["{$dir}/a.php"], allowDirty: false))
        ->not->toThrow(DirtyWorkingTreeException::class);
})->skip(fn() => !gitAvailable(), 'git is unavailable on this runner');

it('refuses naming the path when the target file has uncommitted changes', function (): void {
    $dir = makeTempGitRepo();
    file_put_contents("{$dir}/a.php", "<?php\n");
    commit($dir);
    file_put_contents("{$dir}/a.php", "<?php // dirty\n");

    expect(fn() => new WorkingTreeGuard()->assertClean(["{$dir}/a.php"], allowDirty: false))
        ->toThrow(DirtyWorkingTreeException::class, 'a.php');
})->skip(fn() => !gitAvailable(), 'git is unavailable on this runner');

it('allows a dirty tree when --allow-dirty is set', function (): void {
    $dir = makeTempGitRepo();
    file_put_contents("{$dir}/a.php", "<?php\n");
    commit($dir);
    file_put_contents("{$dir}/a.php", "<?php // dirty\n");

    expect(fn() => new WorkingTreeGuard()->assertClean(["{$dir}/a.php"], allowDirty: true))
        ->not->toThrow(DirtyWorkingTreeException::class);
})->skip(fn() => !gitAvailable(), 'git is unavailable on this runner');

it('refuses a file that is not in a git repository (not-a-repo failure mode)', function (): void {
    $dir = sys_get_temp_dir() . '/openapi-wtg-norepo-' . uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/a.php", "<?php\n");

    expect(fn() => new WorkingTreeGuard()->assertClean(["{$dir}/a.php"], allowDirty: false))
        ->toThrow(DirtyWorkingTreeException::class, 'Cannot verify a clean working tree');

    new Process(['rm', '-rf', $dir])->run();
})->skip(fn() => !gitAvailable(), 'git is unavailable on this runner');

it('allows a non-repo file when --allow-dirty is set', function (): void {
    $dir = sys_get_temp_dir() . '/openapi-wtg-norepo-' . uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/a.php", "<?php\n");

    expect(fn() => new WorkingTreeGuard()->assertClean(["{$dir}/a.php"], allowDirty: true))
        ->not->toThrow(DirtyWorkingTreeException::class);

    new Process(['rm', '-rf', $dir])->run();
});

it('is a no-op with no target files (nothing destructive to guard)', function (): void {
    expect(fn() => new WorkingTreeGuard()->assertClean([], allowDirty: false))
        ->not->toThrow(DirtyWorkingTreeException::class);
});

it('refuses when git is unavailable (binary cannot launch)', function (): void {
    // The git-absent failure mode: Process throws ProcessStartFailedException when the binary cannot
    // launch, distinct from the not-a-repo branch (non-zero exit, no throw). Force it deterministically
    // with a guaranteed-missing executable rather than mutating PATH on the runner.
    $dir = sys_get_temp_dir() . '/openapi-wtg-nogit-' . uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/a.php", "<?php\n");

    $guard = new WorkingTreeGuard('git-does-not-exist-' . uniqid());

    try {
        expect(fn() => $guard->assertClean(["{$dir}/a.php"], allowDirty: false))
            ->toThrow(DirtyWorkingTreeException::class, 'Cannot verify a clean working tree');
    } finally {
        new Process(['rm', '-rf', $dir])->run();
    }
});
