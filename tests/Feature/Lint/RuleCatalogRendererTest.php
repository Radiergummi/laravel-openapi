<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\RuleCatalogRenderer;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;

beforeEach(function (): void {
    $this->registry = app(RuleRegistry::class);
    $this->renderer = new RuleCatalogRenderer();
});

it('renders the catalog as JSON sorted by level then id', function (): void {
    $json = $this->renderer->render($this->registry, 'json');
    $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($rows)->toBeArray()->not->toBeEmpty();
    expect($rows[0])->toHaveKeys(['id', 'level', 'description']);

    $levels = array_column($rows, 'level');
    $sorted = $levels;
    sort($sorted);
    expect($levels)->toBe($sorted);
})->group('openapi', 'lint');

it('renders a Markdown table with a header row', function (): void {
    $md = $this->renderer->render($this->registry, 'markdown');

    expect($md)->toContain('| Rule ID | Level | Description |');
    expect($md)->toContain('| `spec.invalid` | 0 |');
})->group('openapi', 'lint');

it('renders a CLI table', function (): void {
    $cli = $this->renderer->render($this->registry, 'cli');

    expect($cli)->toContain('spec.invalid');
})->group('openapi', 'lint');

it('rejects an unknown format', function (): void {
    $this->renderer->render($this->registry, 'xml');
})->throws(InvalidArgumentException::class)->group('openapi', 'lint');
