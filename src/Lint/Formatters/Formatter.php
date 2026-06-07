<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Finding;
use Symfony\Component\Console\Output\OutputInterface;

interface Formatter
{
    /**
     * @param list<Finding> $findings
     */
    public function render(
        array $findings,
        int $level,
        int $exitCode,
        OutputInterface $output,
        ?CoverageSummary $coverage = null,
    ): void;
}
