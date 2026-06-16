<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use JsonException;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
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
 * Renders the registered lint-rule catalog for `openapi:lint --list` in CLI, JSON, or Markdown.
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
            // Cobertura has no catalog representation; fall back to the JSON listing.
            LinterOutputFormat::Json,
            LinterOutputFormat::Cobertura,
            LinterOutputFormat::Lcov => $this->renderJson($rows, $output),
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
            static fn(Rule $rule): array
                => [
                    'id' => $rule->id(),
                    'level' => $rule->severity()->value,
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
     * Maps a severity level to a Symfony console color tag.
     */
    private function levelColor(int $level): string
    {
        // No "orange" in Symfony's palette; bright-yellow approximates it.
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
     * {@see TableStyle} configured for GitHub-flavoured markdown output.
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
