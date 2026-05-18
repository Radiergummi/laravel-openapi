<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Formatters;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LinterSummary;
use Override;
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
use function strtoupper;
use function Termwind\terminal;
use function wordwrap;

use const PHP_EOL;

final class CliFormatter implements Formatter
{
    private const array LEVEL_ICONS = [
        0 => '❌',
        1 => '⚠️',
        2 => 'ℹ️',
    ];

    private const array LEVEL_SINGULAR = [
        0 => 'error',
        1 => 'warning',
        2 => 'notice',
    ];

    private const array LEVEL_PLURAL = [
        0 => 'errors',
        1 => 'warnings',
        2 => 'notices',
    ];

    private const array LEVEL_COLORS = [
        0 => 'red',
        1 => 'yellow',
        2 => 'blue',
    ];

    public function __construct(private readonly string $basePath = '') {}

    /**
     * @param list<Finding> $findings
     */
    #[Override]
    public function render(
        array $findings,
        int $level,
        int $exitCode,
        OutputInterface $output,
    ): void {
        $grouped = $this->groupByFile($findings);

        // Render findings without a source location first
        if (isset($grouped[''])) {
            $this->renderFileGroup(null, $grouped[''], $output);
            unset($grouped['']);
        }

        // Render remaining groups sorted alphabetically by file path
        ksort($grouped);

        foreach ($grouped as $file => $group) {
            $this->renderFileGroup($file, $group, $output);
        }

        $this->renderSummary(new LinterSummary($findings, $level), $output);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, Finding[]>
     */
    private function groupByFile(array $findings): array
    {
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
        /** @noinspection PhpPipeOperatorCanBeUsedInspection */
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
            $icon = self::LEVEL_ICONS[$finding->level] ?? '?';

            $output->writeln(
                sprintf(
                    '%s %s <fg=%s;options=bold>%s</>',
                    $connector,
                    $icon,
                    'bright-' . (self::LEVEL_COLORS[$finding->level] ?? 'white'),
                    $finding->ruleId,
                ),
            );
            $output->writeln(
                sprintf(
                    '<fg=%s>%s</>',
                    self::LEVEL_COLORS[$finding->level] ?? 'white',
                    $this->wrapText($finding->message, "{$continuation}   "),
                ),
            );

            $locationDetail = $this->formatLocationDetail($finding, $file !== null);

            if ($locationDetail !== null) {
                /** @noinspection PhpPipeOperatorCanBeUsedInspection */
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

    private function wrapText(string $text, string $prefix = ''): string
    {
        $lines = explode(PHP_EOL, wordwrap($text, min(120, terminal()->width()) - strlen($prefix)));

        return implode(PHP_EOL, array_map(static fn(string $line) => $prefix . $line, $lines));
    }

    private function renderSummary(LinterSummary $summary, OutputInterface $output): void
    {
        $summaryParts = [];

        foreach ($summary->findingCountsPerLevel as $severity => $count) {
            $label = $count === 1
                ? self::LEVEL_SINGULAR[$severity] ?? "L{$severity}"
                : self::LEVEL_PLURAL[$severity] ?? "L{$severity}";
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

    /**
     * Format the location detail for a finding. When grouped by file, we omit the file path
     * itself (already in the header) and show only the line number and route info.
     */
    private function formatLocationDetail(Finding $finding, bool $hasFile): ?string
    {
        $location = $finding->location;
        $parts = [];

        if ($hasFile && $location->line !== null) {
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

    private function formatRoute(string $method, string $uri): string
    {
        $verb = sprintf('<options=bold>%s</>', strtoupper($method));
        $uri = preg_replace('/\{(\w+)(\??)}/', '<fg=cyan>{$1$2}</>', $uri);

        return sprintf('%s %s', $verb, $uri);
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
}
