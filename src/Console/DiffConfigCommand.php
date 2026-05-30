<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Radiergummi\OpenApi\Support\Config\ConfigDiffer;

use function sprintf;
use function var_export;

class DiffConfigCommand extends Command
{
    // Name/description use the version-portable string-property form rather than
    // the #[Signature]/#[Description] attributes, which are Laravel 13+ only
    // (Illuminate\Console\Attributes does not exist on Laravel 12).
    protected $signature = 'openapi:diff:config';

    protected $description = 'Show drift between the published config/openapi.php and the package default';
    public function handle(ConfigRepository $config): int
    {
        $default = require __DIR__ . '/../../config/openapi.php';
        $user = $config->get('openapi', []);

        $diffs = ConfigDiffer::diff($default, $user);

        if ($diffs === []) {
            $this->info('Your openapi config matches the package default.');

            return self::SUCCESS;
        }

        $this->line('Drift between your openapi config and the package default:');
        $this->newLine();

        foreach ($diffs as $diff) {
            $path = $diff['path'];

            match ($diff['kind']) {
                'added' => $this->line(
                    sprintf(
                        '  + %s — new in default (default: %s)',
                        $path,
                        var_export($diff['default'] ?? null, true),
                    ),
                ),
                'removed' => $this->line(
                    sprintf(
                        '  - %s — present in your config but not in default (your value: %s)',
                        $path,
                        var_export($diff['user'] ?? null, true),
                    ),
                ),
                'changed' => $this->line(
                    sprintf(
                        '  ~ %s — default %s, yours %s',
                        $path,
                        var_export($diff['default'] ?? null, true),
                        var_export($diff['user'] ?? null, true),
                    ),
                ),
                default => null,
            };
        }

        return self::SUCCESS;
    }
}
