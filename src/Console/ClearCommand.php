<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

use function file_exists;
use function unlink;

#[Signature('openapi:clear
        {spec? : Name of the spec to clear. Omit to clear every defined spec.}')]
#[Description('Remove the generated OpenAPI specification file(s)')]
class ClearCommand extends Command
{
    public const string ARGUMENT_SPEC = 'spec';

    public function handle(SpecRegistry $registry): int
    {
        $specName = $this->argument(self::ARGUMENT_SPEC);

        try {
            $targets = $specName === null ? $registry->all() : [$registry->get((string) $specName)];
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($targets as $spec) {
            if (file_exists($spec->outputPath)) {
                unlink($spec->outputPath);
            }
            $this->components->info("Cleared {$spec->outputPath}");
        }

        return self::SUCCESS;
    }
}
