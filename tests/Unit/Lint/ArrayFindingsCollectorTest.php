<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;

uses()->group('openapi', 'lint');

it('implements FindingsCollector', function (): void {
    expect(new ArrayFindingsCollector())->toBeInstanceOf(FindingsCollector::class);
});

it('accumulates emitted findings in insertion order', function (): void {
    $collector = new ArrayFindingsCollector();
    $a = new Finding('rule.a', 0, 'a', new FindingLocation());
    $b = new Finding('rule.b', 1, 'b', new FindingLocation());

    $collector->emit($a);
    $collector->emit($b);

    expect($collector->all())->toBe([$a, $b]);
});

it('returns empty array when nothing emitted', function (): void {
    expect((new ArrayFindingsCollector())->all())->toBe([]);
});
