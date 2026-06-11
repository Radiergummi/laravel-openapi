<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use DOMDocument;
use DOMElement;
use Override;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function ksort;

/**
 * Emits documentation coverage as a Cobertura XML report keyed to controller source lines, for
 * Codecov / Coveralls / SonarQube to consume.
 *
 * Each in-scope operation maps to one line (its controller action's start line); covered → `hits=1`,
 * uncovered → `hits=0`. Operations with no single source line (closure routes, inference-only facts)
 * carry null file/line and are excluded — coverage gutters pointing at the wrong line erode trust.
 * When two operations share a file+line (a multi-verb route on one method), the line is reported
 * uncovered if any of them is uncovered, so the gap surfaces rather than hides.
 *
 * Findings/level/exitCode are not used: this formatter reports coverage only.
 *
 * @internal
 */
final class CoberturaFormatter implements Formatter
{
    /**
     * @param list<\Radiergummi\OpenApi\Lint\Finding> $findings
     */
    #[Override]
    public function render(
        array $findings,
        int $level,
        int $exitCode,
        OutputInterface $output,
        ?CoverageSummary $coverage = null,
    ): void {
        $output->writeln($this->buildDocument($coverage)->saveXML() ?: '');
    }

    private function buildDocument(?CoverageSummary $coverage): DOMDocument
    {
        $byFile = $this->groupByFile($coverage);

        $validLines = 0;
        $coveredLines = 0;

        foreach ($byFile as $lines) {
            foreach ($lines as $covered) {
                $validLines++;
                $coveredLines += $covered ? 1 : 0;
            }
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElement('coverage');
        $root->setAttribute('line-rate', $this->rate($coveredLines, $validLines));
        $root->setAttribute('branch-rate', '0');
        $root->setAttribute('lines-covered', (string) $coveredLines);
        $root->setAttribute('lines-valid', (string) $validLines);
        $root->setAttribute('branches-covered', '0');
        $root->setAttribute('branches-valid', '0');
        $root->setAttribute('complexity', '0');
        $root->setAttribute('timestamp', '0');
        $root->setAttribute('version', 'laravel-openapi ' . ($coverage->generatorVersion ?? 'unknown'));
        $document->appendChild($root);

        $sources = $document->createElement('sources');
        $sources->appendChild($document->createElement('source', '.'));
        $root->appendChild($sources);

        $packages = $document->createElement('packages');
        $package = $document->createElement('package');
        $package->setAttribute('name', 'openapi');
        $package->setAttribute('line-rate', $this->rate($coveredLines, $validLines));
        $package->setAttribute('branch-rate', '0');
        $package->setAttribute('complexity', '0');
        $classes = $document->createElement('classes');
        $package->appendChild($classes);
        $packages->appendChild($package);
        $root->appendChild($packages);

        foreach ($byFile as $file => $lines) {
            $classes->appendChild($this->buildClass($document, $file, $lines));
        }

        return $document;
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

    /**
     * @param array<int, bool> $lines
     */
    private function buildClass(DOMDocument $document, string $file, array $lines): DOMElement
    {
        $covered = count(array_filter($lines));

        $class = $document->createElement('class');
        $class->setAttribute('name', $file);
        $class->setAttribute('filename', $file);
        $class->setAttribute('line-rate', $this->rate($covered, count($lines)));
        $class->setAttribute('branch-rate', '0');
        $class->setAttribute('complexity', '0');
        $class->appendChild($document->createElement('methods'));

        $lineList = $document->createElement('lines');

        foreach ($lines as $number => $isCovered) {
            $line = $document->createElement('line');
            $line->setAttribute('number', (string) $number);
            $line->setAttribute('hits', $isCovered ? '1' : '0');
            $lineList->appendChild($line);
        }

        $class->appendChild($lineList);

        return $class;
    }

    private function rate(int $covered, int $valid): string
    {
        return $valid === 0 ? '0' : (string) ($covered / $valid);
    }
}
