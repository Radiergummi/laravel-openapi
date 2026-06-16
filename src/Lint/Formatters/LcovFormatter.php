<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use Override;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function ksort;
use function sprintf;

/**
 * Emits documentation coverage as an LCOV tracefile keyed to controller source lines.
 *
 * Each operation maps to its action's start line (covered=1, uncovered=0). Operations with no
 * source line are excluded. When two operations share a file+line, the line is uncovered if
 * any of them is uncovered.
 *
 * @internal
 */
final class LcovFormatter implements Formatter
{
    #[Override]
    public function render(LintResult $result, OutputInterface $output): void
    {
        $byFile = $this->groupByFile($result->coverage);

        foreach ($byFile as $file => $lines) {
            $output->writeln('TN:openapi');
            $output->writeln(sprintf('SF:%s', $file));

            $coveredLines = 0;

            foreach ($lines as $number => $covered) {
                $output->writeln(sprintf('DA:%d,%d', $number, $covered ? 1 : 0));
                $coveredLines += $covered ? 1 : 0;
            }

            $output->writeln(sprintf('LH:%d', $coveredLines));
            $output->writeln(sprintf('LF:%d', count($lines)));
            $output->writeln('end_of_record');
        }
    }

    /**
     * @return array<string, array<int, bool>>
     */
    private function groupByFile(?CoverageSummary $coverage): array
    {
        $byFile = [];

        foreach ($coverage->perOperation ?? [] as $operation) {
            if ($operation['file'] === null || $operation['line'] === null) {
                continue;
            }

            $file = $operation['file'];
            $line = $operation['line'];
            $covered = $operation['covered'];

            $byFile[$file][$line] = ($byFile[$file][$line] ?? true) && $covered;
        }

        ksort($byFile);

        foreach ($byFile as &$lines) {
            ksort($lines);
        }

        return $byFile;
    }
}
