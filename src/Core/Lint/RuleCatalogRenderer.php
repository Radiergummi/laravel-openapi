<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use InvalidArgumentException;

use function array_map;
use function implode;
use function json_encode;
use function max;
use function sprintf;
use function str_pad;
use function strlen;
use function usort;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Renders the registered lint-rule catalog (id, level, description) for the
 * `openapi:lint --list` command, in CLI, JSON, or Markdown form.
 */
final readonly class RuleCatalogRenderer
{
    public function render(RuleRegistry $registry, string $format): string
    {
        $rows = $this->rows($registry);

        return match ($format) {
            'cli' => $this->renderCli($rows),
            'json' => $this->renderJson($rows),
            'markdown' => $this->renderMarkdown($rows),
            default => throw new InvalidArgumentException("Unknown catalog format: {$format}"),
        };
    }

    /**
     * @return list<array{id: string, level: int, description: string}>
     */
    private function rows(RuleRegistry $registry): array
    {
        $rows = array_map(
            static fn(Rule $rule): array => [
                'id' => $rule->id(),
                'level' => $rule->level(),
                'description' => $rule->description(),
            ],
            $registry->all(),
        );

        usort(
            $rows,
            static fn(array $a, array $b): int
                => [$a['level'], $a['id']] <=> [$b['level'], $b['id']],
        );

        return $rows;
    }

    /**
     * @param list<array{id: string, level: int, description: string}> $rows
     */
    private function renderJson(array $rows): string
    {
        return json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param list<array{id: string, level: int, description: string}> $rows
     */
    private function renderMarkdown(array $rows): string
    {
        $lines = [
            '| Rule ID | Level | Description |',
            '|---|---|---|',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| `%s` | %d | %s |',
                $row['id'],
                $row['level'],
                $row['description'],
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{id: string, level: int, description: string}> $rows
     */
    private function renderCli(array $rows): string
    {
        $idWidth = 0;

        foreach ($rows as $row) {
            $idWidth = max($idWidth, strlen($row['id']));
        }

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '%s  L%d  %s',
                str_pad($row['id'], $idWidth),
                $row['level'],
                $row['description'],
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
