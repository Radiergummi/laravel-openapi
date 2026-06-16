<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Scoped;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * Writes lint findings to the PSR-3 logger, mapping Broken to error, Degraded to warning, rest to info.
 */
#[Scoped]
final readonly class LoggingFindingsCollector implements FindingsCollector
{
    public function __construct(private LoggerInterface $logger) {}

    #[Override]
    public function emit(Finding $finding): void
    {
        $message = "[OpenAPI] {$finding->ruleId}: {$finding->message}";
        $context = [
            'rule_id' => $finding->ruleId,
            'level' => $finding->severity->value,
            'route' => $finding->location->routeName,
            'file' => $finding->location->file,
            'line' => $finding->location->line,
            'fix' => $finding->fixHint,
        ];

        match ($finding->severity) {
            Severity::Broken => $this->logger->error($message, $context),
            Severity::Degraded => $this->logger->warning($message, $context),
            Severity::Underspecified,
            Severity::Inconsistent,
            Severity::Improvable => $this->logger->info($message, $context),
        };
    }
}
