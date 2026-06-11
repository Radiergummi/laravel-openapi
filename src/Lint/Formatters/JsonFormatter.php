<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use JsonException;
use Override;
use Radiergummi\OpenApi\Lint\LinterSummary;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class JsonFormatter implements Formatter
{
    private const string SCHEMA_VERSION = '1';

    /**
     * @throws JsonException
     */
    #[Override]
    public function render(LintResult $result, OutputInterface $output): void
    {
        $output->writeln(
            json_encode(
                value: $this->buildPayload($result),
                flags: JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(LintResult $result): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $result->level,
            'exit_code' => $result->exitCode,
            'findings' => $result->findings,
            'summary' => new LinterSummary($result->findings, $result->level),
        ];

        if ($result->coverage !== null) {
            $payload['coverage'] = $result->coverage;
        }

        return $payload;
    }
}
