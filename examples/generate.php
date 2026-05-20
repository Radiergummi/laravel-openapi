<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 *
 * Usage: php examples/generate.php <flavor>
 */

declare(strict_types=1);

use Examples\Shared\Flavors;
use Examples\Shared\TestbenchBoot;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$flavor = $argv[1] ?? null;

if ($flavor === null) {
    fwrite(STDERR, "Usage: php examples/generate.php <flavor>\n");
    exit(2);
}

$providers = Flavors::all();

if (!isset($providers[$flavor])) {
    fwrite(STDERR, "Unknown flavor: {$flavor}. Known: " . implode(', ', array_keys($providers)) . "\n");
    exit(2);
}

$app = TestbenchBoot::boot($providers[$flavor]);

$status = $app->make(Kernel::class)->call('openapi:generate', [
    'path'     => __DIR__ . "/{$flavor}/openapi.yaml",
    '--format' => 'yaml',
]);

exit($status);
