<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Container\Attributes\Scoped;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Writes lint findings to the PSR-3 logger, mapping level 0 → error, 1 → warning, rest → info.
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
            'level' => $finding->level,
            'route' => $finding->location->routeName,
            'file' => $finding->location->file,
            'line' => $finding->location->line,
            'fix' => $finding->fixHint,
        ];

        match ($finding->level) {
            0 => $this->logger->error($message, $context),
            1 => $this->logger->warning($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}
