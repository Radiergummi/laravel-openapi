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
use Override;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function rtrim;
use function str_replace;

final class GithubFormatter implements Formatter
{
    private const array LEVEL_COMMANDS = [
        0 => 'error',
        1 => 'warning',
        2 => 'notice',
    ];

    /**
     * @param list<Finding> $findings
     */
    #[Override]
    public function render(array $findings, int $level, int $exitCode, OutputInterface $output): void
    {
        foreach ($findings as $finding) {
            $output->writeln($this->formatCommand($finding));
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

    private function buildBody(Finding $finding): string
    {
        $body = $finding->message;

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

    /**
     * Percent-encode a workflow command body value.
     * GitHub requires: % → %25, \r → %0D, \n → %0A.
     */
    private function encodeBody(string $value): string
    {
        return str_replace(
            ['%',   "\r",  "\n"],
            ['%25', '%0D', '%0A'],
            $value,
        );
    }

    /**
     * Percent-encode a workflow command property value (file=, title=, etc.).
     * Same as body, plus: , → %2C, : → %3A.
     */
    private function encodePropertyValue(string $value): string
    {
        return str_replace(
            [',',   ':'],
            ['%2C', '%3A'],
            $this->encodeBody($value),
        );
    }
}
