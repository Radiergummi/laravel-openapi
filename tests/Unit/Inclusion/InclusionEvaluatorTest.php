<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Inclusion;

use Radiergummi\OpenApi\Core\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Core\Inclusion\TraceEntry;

it('TraceEntry holds stage, name, passed, reason', function (): void {
    $entry = new TraceEntry('global-filter', 'SkipNovaRoutes', true, 'not a Nova route');

    expect($entry->stage)->toBe('global-filter')
        ->and($entry->name)->toBe('SkipNovaRoutes')
        ->and($entry->passed)->toBeTrue()
        ->and($entry->reason)->toBe('not a Nova route');
});

it('InclusionDecision holds included, trace, summary', function (): void {
    $decision = new InclusionDecision(true, [], 'matches default spec');

    expect($decision->included)->toBeTrue()
        ->and($decision->trace)->toBe([])
        ->and($decision->summary)->toBe('matches default spec');
});
