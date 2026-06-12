<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function rtrim;
use function sprintf;
use function str_replace;

final class GithubFormatter implements Formatter
{
    private const array LEVEL_COMMANDS = [
        0 => 'error',
        1 => 'warning',
        2 => 'notice',
    ];

    #[Override]
    public function render(LintResult $result, OutputInterface $output): void
    {
        foreach ($result->findings as $finding) {
            $output->writeln($this->formatCommand($finding));
        }

        if ($result->coverage !== null) {
            $suffix = $result->coverage->unattributedFindings > 0
                ? sprintf(', %d unattributed', $result->coverage->unattributedFindings)
                : '';

            $output->writeln(
                sprintf(
                    '::notice title=OpenAPI coverage::%.2f%% (%d/%d operations)%s',
                    $result->coverage->coveragePercent,
                    $result->coverage->coveredOperations,
                    $result->coverage->totalOperations,
                    $suffix,
                ),
            );
        }
    }

    private function formatCommand(Finding $finding): string
    {
        $command = self::LEVEL_COMMANDS[$finding->level] ?? 'notice';
        $params = $this->buildParams($finding);
        $body = $this->buildBody($finding);

        return "::{$command} {$params}::{$body}";
    }

    private function buildParams(Finding $finding): string
    {
        $parts = [];

        if ($finding->location->file !== null) {
            $parts[] = 'file=' . $this->encodePropertyValue($finding->location->file);
        }

        if ($finding->location->line !== null) {
            $parts[] = "line={$finding->location->line}";
        }

        $parts[] = 'title=' . $this->encodePropertyValue($finding->ruleId);

        return implode(',', $parts);
    }

    /**
     * Percent-encode a workflow command property value (file=, title=, etc.).
     * Same as body, plus: , → %2C, : → %3A.
     */
    private function encodePropertyValue(string $value): string
    {
        return str_replace(
            [',', ':'],
            ['%2C', '%3A'],
            $this->encodeBody($value),
        );
    }

    /**
     * Percent-encode a workflow command body value.
     * GitHub requires: % → %25, \r → %0D, \n → %0A.
     */
    private function encodeBody(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $value,
        );
    }

    private function buildBody(Finding $finding): string
    {
        $body = $finding->spec !== null
            ? "[spec:{$finding->spec}] {$finding->message}"
            : $finding->message;

        if ($finding->fixHint !== null) {
            // Strip trailing period from message to avoid double period before ". Fix: …"
            $body = rtrim($body, '.') . ". Fix: {$finding->fixHint}";

            // Ensure the body ends with a period
            if (!str_ends_with($body, '.')) {
                $body .= '.';
            }
        }

        return $this->encodeBody($body);
    }
}
