<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

use function config;
use function file_exists;
use function unlink;

class ClearCommand extends Command
{
    protected $name = 'openapi:clear';

    protected $description = 'Remove the generated OpenAPI specification';

    public function handle(): int
    {
        $path = $this->argument('path');

        if ($path === '-') {
            $this->components->warn('Cannot clear stdout output.');

            return self::FAILURE;
        }

        if (file_exists($path)) {
            unlink($path);
        }

        $this->components->info('OpenAPI specification cleared.');

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Path to the specification file to remove.',
            (string) config('openapi.output_path'),
        );
    }
}
