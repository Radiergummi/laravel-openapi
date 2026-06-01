<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use JsonException;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LinterSummary;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class JsonFormatter implements Formatter
{
    private const string SCHEMA_VERSION = '1';

    /**
     * @param list<Finding> $findings
     *
     * @throws JsonException
     */
    #[Override]
    public function render(
        array $findings,
        int $level,
        int $exitCode,
        OutputInterface $output,
    ): void {
        $output->writeln(
            json_encode(
                value: $this->buildPayload($findings, $level, $exitCode),
                flags: JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array{
     *     schema_version: string,
     *     level: int,
     *     exit_code: int,
     *     findings: list<Finding>,
     *     summary: LinterSummary,
     * }
     */
    private function buildPayload(array $findings, int $level, int $exitCode): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $level,
            'exit_code' => $exitCode,
            'findings' => $findings,
            'summary' => new LinterSummary($findings, $level),
        ];
    }
}
