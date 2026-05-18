<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Psr\Log\LoggerInterface;

final readonly class LoggingFindingsCollector implements FindingsCollector
{
    public function __construct(private LoggerInterface $logger) {}

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
