<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\OutputInterface;

use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;

/**
 * Renders a lint run as GitHub-Flavored Markdown: a coverage summary line plus a findings table
 * with one row per finding. Suitable for dropping into a PR comment verbatim (`--format=markdown`).
 */
final class MarkdownFormatter implements Formatter
{
    #[Override]
    public function render(LintResult $result, OutputInterface $output): void
    {
        if ($result->coverage !== null) {
            $coverage = $result->coverage;
            $suffix = $coverage->unattributedFindings > 0
                ? sprintf(', %d unattributed', $coverage->unattributedFindings)
                : '';

            $output->writeln([
                '## OpenAPI lint',
                '',
                sprintf(
                    'Coverage: **%.2f%%** (%d/%d operations)%s',
                    $coverage->coveragePercent,
                    $coverage->coveredOperations,
                    $coverage->totalOperations,
                    $suffix,
                ),
                '',
            ]);
        }

        if ($result->findings === []) {
            $output->writeln('No findings.');

            return;
        }

        $output->writeln([
            '| Severity | Rule | Location | Message |',
            '| --- | --- | --- | --- |',
        ]);

        foreach ($result->findings as $finding) {
            $output->writeln($this->buildRow($finding));
        }
    }

    private function buildRow(Finding $finding): string
    {
        return sprintf(
            '| %s | `%s` | %s | %s |',
            $this->severityLabel($finding->severity),
            $this->escapeCell($finding->ruleId),
            $this->location($finding),
            $this->escapeCell($this->message($finding)),
        );
    }

    private function severityLabel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Broken => 'Error',
            Severity::Degraded => 'Warning',
            Severity::Underspecified,
            Severity::Inconsistent,
            Severity::Improvable => 'Notice',
        };
    }

    /** `file:line` when known, else the route `METHOD uri`, else empty. */
    private function location(Finding $finding): string
    {
        $location = $finding->location;

        if ($location->file !== null) {
            $label = $location->line !== null
                ? "{$location->file}:{$location->line}"
                : $location->file;

            return '`' . $this->escapeCell($label) . '`';
        }

        if ($location->routeMethod !== null && $location->routeUri !== null) {
            return $this->escapeCell(
                sprintf('%s %s', $location->routeMethod->forDisplay(), $location->routeUri),
            );
        }

        return '';
    }

    private function message(Finding $finding): string
    {
        $message = $finding->spec !== null
            ? "[spec:{$finding->spec}] {$finding->message}"
            : $finding->message;

        if ($finding->fixHint !== null) {
            $message = rtrim($message, '.') . ". Fix: {$finding->fixHint}";

            if (!str_ends_with($message, '.')) {
                $message .= '.';
            }
        }

        return $message;
    }

    /** Escape pipes (so cells don't split) and collapse newlines (so rows stay on one line). */
    private function escapeCell(string $value): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\|', ' ', ' ', ' '], $value);
    }
}
