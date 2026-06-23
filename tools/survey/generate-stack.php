<?php

declare(strict_types=1);

/**
 * Stack-enabled spec generator (app-context).
 *
 * Boots a consumer app and generates the OpenAPI document with the bundled plugins the app's own
 * dependencies imply additionally enabled (e.g. `league/fractal` installed → `FractalPlugin`), on top
 * of whatever its config already lists. This is the "stack-enabled" variant the survey reports next to
 * the out-of-the-box (published-default) one: enabling an opt-in plugin the stack obviously needs is a
 * one-line config change, and measuring only the defaults understates coverage for those apps (#443).
 *
 * The plugin set is overridden at runtime via `config()` — the app's committed config is never touched.
 * Detection is `class_exists()` on each integration package's marker class (no body parsing).
 *
 * Emits the generated JSON document to stdout (empty output on failure). Usage:
 *   php generate-stack.php <repo-dir> > generated-spec.stack.json
 */

use Illuminate\Support\Facades\Artisan;

require_once __DIR__ . '/metrics.php';

$repoDir = $argv[1] ?? null;

if ($repoDir === null || !is_dir($repoDir)) {
    fwrite(STDERR, "usage: generate-stack.php <repo-dir>\n");
    exit(2);
}

require $repoDir . '/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $repoDir . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Detect installed integration packages by their marker class, then map to the plugins they enable.
$markers = [
    'league/fractal' => 'League\Fractal\Manager',
    'spatie/laravel-query-builder' => 'Spatie\QueryBuilder\QueryBuilder',
];

$detected = [];

foreach ($markers as $package => $markerClass) {
    if (class_exists($markerClass)) {
        $detected[] = $package;
    }
}

$extraPlugins = survey_stackPlugins($detected);

// Merge onto the configured plugins, normalising leading backslashes so a plugin the app already
// lists is not added twice.
$normalise = static fn(string $class): string => ltrim($class, '\\');
$configured = array_map($normalise, (array) config('openapi.plugins', []));
$merged = $configured;

foreach ($extraPlugins as $plugin) {
    if (!in_array($normalise($plugin), $merged, true)) {
        $merged[] = $normalise($plugin);
    }
}

config(['openapi.plugins' => $merged]);

// Generate to a temp file: the command writes `--output=<path>` via file_put_contents (capturable),
// whereas `--output=-` writes straight to STDOUT and would bypass this script.
$tmp = tempnam(sys_get_temp_dir(), 'survey-stack-spec-');

if ($tmp === false) {
    fwrite(STDERR, "generate-stack.php: could not create a temp file\n");
    exit(1);
}

$exit = Artisan::call('openapi:generate', ['--output' => $tmp, '--format' => 'json', '--no-validate' => true]);
$content = (string) @file_get_contents($tmp);
@unlink($tmp);

if ($exit !== 0 || $content === '') {
    fwrite(STDERR, "generate-stack.php: generation failed (exit {$exit})\n");
    exit($exit === 0 ? 1 : $exit);
}

echo $content;
