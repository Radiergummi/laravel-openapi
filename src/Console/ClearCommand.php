<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

use function file_exists;
use function unlink;

class ClearCommand extends Command
{
    // Name/description use the version-portable string-property form rather than
    // the #[Signature]/#[Description] attributes, which are Laravel 13+ only
    // (Illuminate\Console\Attributes does not exist on Laravel 12).
    protected $signature = 'openapi:clear
        {spec? : Name of the spec to clear. Omit to clear every defined spec.}';

    protected $description = 'Remove the generated OpenAPI specification file(s)';

    /**
     * @throws InvalidArgumentException
     */
    public function handle(SpecRegistry $registry): int
    {
        $specName = $this->argument('spec');

        try {
            $targets = $specName === null ? $registry->all() : [$registry->get((string) $specName)];
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

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
