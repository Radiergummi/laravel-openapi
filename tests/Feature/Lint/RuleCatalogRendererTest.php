<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LinterOutputFormat;
use Radiergummi\OpenApi\Core\Lint\RuleCatalogRenderer;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    $this->registry = app(RuleRegistry::class);
    $this->renderer = new RuleCatalogRenderer();
});

function renderCatalog(RuleCatalogRenderer $renderer, RuleRegistry $registry, LinterOutputFormat $format): string
{
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
    $renderer->render($registry, $format, $output);

    return $output->fetch();
}

it('renders the catalog as JSON sorted by level then id', function (): void {
    $json = renderCatalog($this->renderer, $this->registry, LinterOutputFormat::Json);
    $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($rows)->toBeArray()->not->toBeEmpty()
        ->and($rows[0])->toHaveKeys(['id', 'level', 'description']);

    $levels = array_column($rows, 'level');
    $sorted = $levels;
    sort($sorted);
    expect($levels)->toBe($sorted);
});

it('renders a Markdown table with a header row', function (): void {
    $md = renderCatalog($this->renderer, $this->registry, LinterOutputFormat::Markdown);

    // The table is column-padded by Symfony, so assert on row shape rather than exact spacing.
    expect($md)->toMatch('/\| Rule ID +\| Level +\| Description +\|/')
        ->and($md)->toMatch('/\|-+\|-+\|-+\|/')
        ->and($md)->toMatch('/\| `spec\.invalid` +\| 0 +\|/');
});

it('aliases the GitHub format to Markdown', function (): void {
    $md = renderCatalog($this->renderer, $this->registry, LinterOutputFormat::Markdown);
    $gh = renderCatalog($this->renderer, $this->registry, LinterOutputFormat::GitHub);

    expect($gh)->toBe($md);
});

it('renders a CLI table containing every rule id', function (): void {
    $cli = renderCatalog($this->renderer, $this->registry, LinterOutputFormat::Cli);

    expect($cli)->toContain('Rule ID')
        ->and($cli)->toContain('Level')
        ->and($cli)->toContain('Description')
        ->and($cli)->toContain('spec.invalid');
});
