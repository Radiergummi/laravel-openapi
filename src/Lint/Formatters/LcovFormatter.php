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
 * Emits documentation coverage as an LCOV tracefile keyed to controller source lines, for coverage
 * tools (genhtml, Coveralls, Codecov) to consume.
 *
 * Each in-scope operation maps to one line (its controller action's start line); covered → `1`,
 * uncovered → `0`. Operations with no single source line (closure routes, inference-only facts)
 * carry null file/line and are excluded — coverage gutters pointing at the wrong line erode trust.
 * When two operations share a file+line (a multi-verb route on one method), the line is reported
 * uncovered if any of them is uncovered, so the gap surfaces rather than hides.
 *
 * Only `$result->coverage` is used; findings and level are irrelevant to this format.
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
     * Collapse perOperation into file => (lineNumber => covered), dropping null file/line and
     * AND-ing the covered flag when several operations land on the same line.
     *
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
