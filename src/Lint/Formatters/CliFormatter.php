<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LinterSummary;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function count;
use function explode;
use function implode;
use function ksort;
use function min;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strlen;
use function Termwind\terminal;
use function wordwrap;

use const PHP_EOL;

final class CliFormatter implements Formatter
{
    public function __construct(private readonly string $basePath = '') {}

    private function icon(Severity $severity): string
    {
        return match ($severity) {
            Severity::Broken => '❌',
            Severity::Degraded => '⚠️',
            Severity::Underspecified,
            Severity::Inconsistent,
            Severity::Improvable => 'ℹ️',
        };
    }

    private function color(Severity $severity): string
    {
        return match ($severity) {
            Severity::Broken => 'red',
            Severity::Degraded => 'yellow',
            Severity::Underspecified,
            Severity::Inconsistent,
            Severity::Improvable => 'blue',
        };
    }

    private function singular(Severity $severity): string
    {
        return match ($severity) {
            Severity::Broken => 'error',
            Severity::Degraded => 'warning',
            Severity::Underspecified,
            Severity::Inconsistent,
            Severity::Improvable => 'notice',
        };
    }

    private function plural(Severity $severity): string
    {
        return match ($severity) {
            Severity::Broken => 'errors',
            Severity::Degraded => 'warnings',
            Severity::Underspecified,
            Severity::Inconsistent,
            Severity::Improvable => 'notices',
        };
    }

    #[Override]
    public function render(LintResult $result, OutputInterface $output): void
    {
        [$preBuild, $perSpec] = $this->partitionFindings($result->findings);

        // Show section headers whenever there is more than one section. A single per-spec
        // section without pre-build findings (the common case) renders unchanged.
        $sectionCount = ($preBuild === [] ? 0 : 1) + count($perSpec);
        $showHeaders = $sectionCount > 1;

        if ($preBuild !== []) {
            if ($showHeaders) {
                $output->writeln(['', '── configuration ──']);
            }
            $this->renderSection($preBuild, $output);
        }

        foreach ($perSpec as $specName => $specFindings) {
            if ($showHeaders) {
                $output->writeln(['', "── spec: {$specName} ──"]);
            }
            $this->renderSection($specFindings, $output);
        }

        $this->renderSummary(new LinterSummary($result->findings, $result->level), $output);

        if ($result->coverage !== null) {
            $this->renderCoverage($result->coverage, $output);
        }
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array{0: list<Finding>, 1: array<string, list<Finding>>}
     */
    private function partitionFindings(array $findings): array
    {
        $preBuild = [];
        /** @var array<string, list<Finding>> $perSpec */
        $perSpec = [];

        foreach ($findings as $finding) {
            if ($finding->spec === null) {
                $preBuild[] = $finding;
            } else {
                $perSpec[$finding->spec][] = $finding;
            }
        }

        return [$preBuild, $perSpec];
    }

    /**
     * @param list<Finding> $findings
     */
    private function renderSection(array $findings, OutputInterface $output): void
    {
        $grouped = $this->groupByFile($findings);

        if (isset($grouped[''])) {
            $this->renderFileGroup(null, $grouped[''], $output);
            unset($grouped['']);
        }

        ksort($grouped);

        foreach ($grouped as $file => $group) {
            $this->renderFileGroup($file, $group, $output);
        }
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, list<Finding>>
     */
    private function groupByFile(array $findings): array
    {
        /** @var array<string, list<Finding>> $grouped */
        $grouped = [];

        foreach ($findings as $finding) {
            $key = $finding->location->file ?? '';
            $grouped[$key][] = $finding;
        }

        return $grouped;
    }

    /**
     * @param list<Finding> $group
     */
    private function renderFileGroup(?string $file, array $group, OutputInterface $output): void
    {
        $header = $file ? $this->fileLink($file) : '(no source location)';

        $output->writeln(['', '']);
        $output->writeln(
            sprintf(
                '<fg=white;options=underscore>%s</> (%d)',
                $header,
                count($group),
            ),
        );

        $last = count($group) - 1;

        foreach ($group as $index => $finding) {
            $output->writeln(' │ ');

            $isLast = $index === $last;
            $connector = $isLast ? ' ╰─' : ' ├─';
            $continuation = $isLast ? '   ' : ' │ ';
            $color = $this->color($finding->severity);

            $output->writeln(
                sprintf(
                    '%s %s <fg=%s;options=bold>%s</>',
                    $connector,
                    $this->icon($finding->severity),
                    "bright-{$color}",
                    $finding->ruleId,
                ),
            );
            $output->writeln(
                sprintf(
                    '<fg=%s>%s</>',
                    $color,
                    $this->wrapText($finding->message, "{$continuation}   "),
                ),
            );

            $locationDetail = $this->formatLocationDetail($finding, $file !== null);

            if ($locationDetail !== null) {
                $output->writeln(
                    $this->wrapText(
                        sprintf('<fg=gray>at %s</>', $locationDetail),
                        "{$continuation}   ",
                    ),
                );
            }

            if ($finding->fixHint !== null) {
                $output->writeln($continuation);
                $output->writeln(
                    $this->wrapText(
                        "<fg=cyan>Suggested Fix:</> {$finding->fixHint}",
                        "{$continuation}   ",
                    ),
                );
            }
        }
    }

    private function fileLink(string $path, ?int $line = null): string
    {
        $basePath = $this->basePath !== '' ? $this->basePath : base_path('/');
        $label = str_replace($basePath, '', $path);

        if ($line !== null) {
            $label .= ":{$line}";
        }

        return sprintf('<href=file://%s>%s</>', $path, $label);
    }

    private function wrapText(string $text, string $prefix = ''): string
    {
        $lines = explode(PHP_EOL, wordwrap($text, min(120, terminal()->width()) - strlen($prefix)));

        return implode(PHP_EOL, array_map(static fn(string $line): string => $prefix . $line, $lines));
    }

    /** When grouped by file, omits the path (already in the header) and shows only line + route. */
    private function formatLocationDetail(Finding $finding, bool $hasFile): ?string
    {
        $location = $finding->location;
        $parts = [];

        if ($hasFile && $location->line !== null && $location->file !== null) {
            $parts[] = $this->fileLink($location->file, $location->line);
        }

        if ($location->routeMethod !== null && $location->routeUri !== null) {
            $parts[] = sprintf(
                '(%s)',
                $this->formatRoute($location->routeMethod, $location->routeUri),
            );
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function formatRoute(HttpMethod $method, string $uri): string
    {
        $verb = sprintf('<options=bold>%s</>', $method->forDisplay());
        $uri = preg_replace('/\{(\w+)(\??)}/', '<fg=cyan>{$1$2}</>', $uri);

        return sprintf('%s %s', $verb, $uri);
    }

    private function renderSummary(LinterSummary $summary, OutputInterface $output): void
    {
        $summaryParts = [];

        foreach ($summary->findingCountsPerLevel as $level => $count) {
            $severity = Severity::tryFrom($level);

            if ($severity === null) {
                continue;
            }

            $label = $count === 1
                ? $this->singular($severity)
                : $this->plural($severity);
            $summaryParts[] = "{$count} {$label}";
        }

        $output->writeln([
            '',
            '',
            sprintf(
                ' Summary: %s (%d total across %d %s)',
                implode(', ', $summaryParts),
                $summary->total,
                $summary->affectedRoutesCount,
                $summary->affectedRoutesCount === 1 ? 'route' : 'routes',
            ),
            '',
        ]);
    }

    private function renderCoverage(CoverageSummary $coverage, OutputInterface $output): void
    {
        $line = sprintf(
            ' Coverage: %.2f%% (%d/%d operations)',
            $coverage->coveragePercent,
            $coverage->coveredOperations,
            $coverage->totalOperations,
        );

        if ($coverage->unattributedFindings > 0) {
            $line .= sprintf(
                ' · %d unattributed finding%s',
                $coverage->unattributedFindings,
                $coverage->unattributedFindings === 1 ? '' : 's',
            );
        }

        $output->writeln([$line, '']);
    }
}
