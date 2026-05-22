<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use JsonException;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function json_encode;
use function sprintf;
use function usort;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Renders the registered lint-rule catalog (id, level, description) for the
 * `openapi:lint --list` command, in CLI, JSON, or Markdown form.
 *
 * Writes to a Symfony {@see OutputInterface}, so the CLI format can leverage Symfony's
 * {@see Table} helper (column alignment, coloured severity column) and tests can use a
 * {@see \Symfony\Component\Console\Output\BufferedOutput} to assert on the rendered text.
 */
final readonly class RuleCatalogRenderer
{
    /**
     * @throws JsonException
     */
    public function render(
        RuleRegistry $registry,
        LinterOutputFormat $format,
        OutputInterface $output,
    ): void {
        $rows = $this->rows($registry);

        match ($format) {
            LinterOutputFormat::Cli => $this->renderCli($rows, $output),
            LinterOutputFormat::Json => $this->renderJson($rows, $output),
            LinterOutputFormat::GitHub,
            LinterOutputFormat::Markdown => $this->renderMarkdown($rows, $output),
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
            static fn(array $a, array $b): int => [$a['level'], $a['id']] <=> [$b['level'], $b['id']],
        );

        return $rows;
    }

    /**
     * @param list<array{id: string, level: int, description: string}> $rows
     *
     * @throws JsonException
     */
    private function renderJson(array $rows, OutputInterface $output): void
    {
        $output->writeln(
            json_encode(
                $rows,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param list<array{id: string, level: int, description: string}> $rows
     */
    private function renderMarkdown(array $rows, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setStyle($this->markdownTableStyle());
        $table->setHeaders(['Rule ID', 'Level', 'Description']);

        foreach ($rows as $row) {
            $table->addRow([
                sprintf('`%s`', $row['id']),
                (string) $row['level'],
                $row['description'],
            ]);
        }

        $table->render();
    }

    /**
     * @param list<array{id: string, level: int, description: string}> $rows
     */
    private function renderCli(array $rows, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['Rule ID', 'Level', 'Description']);

        $previousLevel = null;

        foreach ($rows as $row) {
            if ($previousLevel !== null && $previousLevel !== $row['level']) {
                $table->addRow(new TableSeparator());
            }

            $table->addRow([
                $row['id'],
                sprintf('<fg=%s>L%d</>', $this->levelColor($row['level']), $row['level']),
                $row['description'],
            ]);

            $previousLevel = $row['level'];
        }

        $table->render();
    }

    /**
     * Returns a valid Symfony tag color for a rule's severity level.
     *
     * Picks green for the lowest band, fading through yellow into red for higher severities.
     */
    private function levelColor(int $level): string
    {
        // Symfony's palette has no "orange"; bright-yellow approximates it.
        return match (true) {
            $level <= 0 => 'blue',
            $level === 1 => 'green',
            $level === 2 => 'cyan',
            $level === 3 => 'bright-yellow',
            $level === 4 => 'yellow',
            default => 'red',
        };
    }

    /**
     * A {@see TableStyle} configured to emit valid GitHub-flavoured markdown.
     */
    private function markdownTableStyle(): TableStyle
    {
        $style = new TableStyle();
        $style->setDisplayOutsideBorder(false);
        $style->setHorizontalBorderChars('-', '-');
        $style->setVerticalBorderChars('|');
        $style->setCrossingChars(
            cross: '|',
            topLeft: '',
            topMid: '',
            topRight: '',
            midRight: '|',
            bottomRight: '',
            bottomMid: '',
            bottomLeft: '',
            midLeft: '|',
            topLeftBottom: '|',
            topMidBottom: '|',
            topRightBottom: '|',
        );
        $style->setCellHeaderFormat('%s');

        return $style;
    }
}
