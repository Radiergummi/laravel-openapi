<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Radiergummi\OpenApi\Core\Lint\Rules\RequestEmpty;
use Radiergummi\OpenApi\Core\Lint\Rules\RuleUnknown;
use Radiergummi\OpenApi\Core\Lint\Rules\ThrowsUnmapped;
use Radiergummi\OpenApi\Plugins\JsonApi\Lint\Rules\ResponseEmpty;
use Radiergummi\OpenApi\Plugins\JsonApi\Lint\Rules\ResponseResourceIndeterminate;
use Radiergummi\OpenApi\Plugins\JsonApi\Lint\Rules\ResponseResourceUnresolvable;

uses()->group('openapi', 'lint');

/**
 * Build a RuleRegistry containing only the six generation-time stub rules.
 *
 * We instantiate the stubs directly rather than going through CoreRegistration
 * and OpenApiRegistry, because some other core rules (e.g. SpecInvalid) call
 * resource_path() at construction time and require a full Laravel application.
 * Unit tests run under a bare container and cannot satisfy that requirement.
 */
function buildRegistry(): RuleRegistry
{
    return new RuleRegistry([
        new RequestEmpty(),
        new ThrowsUnmapped(),
        new RuleUnknown(),
        new ResponseEmpty(),
        new ResponseResourceIndeterminate(),
        new ResponseResourceUnresolvable(),
    ]);
}

// ---------------------------------------------------------------------------
// Known IDs — generation-time core findings
// ---------------------------------------------------------------------------

it('registers request.empty in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('request.empty');
});

it('registers throws.unmapped in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('throws.unmapped');
});

it('registers rule.unknown in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('rule.unknown');
});

// ---------------------------------------------------------------------------
// Known IDs — generation-time JsonApi plugin findings
// ---------------------------------------------------------------------------

it('registers response.empty in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('response.empty');
});

it('registers response.resource.indeterminate in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('response.resource.indeterminate');
});

it('registers responseresource.unresolvable in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('responseresource.unresolvable');
});

// ---------------------------------------------------------------------------
// All 6 IDs present in a single knownIds() call
// ---------------------------------------------------------------------------

it('contains all six generation-time IDs in knownIds', function (): void {
    $ids = buildRegistry()->knownIds();

    expect($ids)
        ->toContain('request.empty')
        ->toContain('throws.unmapped')
        ->toContain('rule.unknown')
        ->toContain('response.empty')
        ->toContain('response.resource.indeterminate')
        ->toContain('responseresource.unresolvable');
});

// ---------------------------------------------------------------------------
// Severity override integration — registry can remap generation-time findings
// ---------------------------------------------------------------------------

it('severity override applies to request.empty', function (): void {
    $registry = new RuleRegistry(
        [new RequestEmpty()],
        severityOverrides: ['request.empty' => 2],
    );

    // With the override in place, effectiveLevelFor returns 2 regardless of
    // the caller's fallback.
    expect($registry->effectiveLevelFor('request.empty', 0))->toBe(2);
});

it('severity override applies to response.empty', function (): void {
    $registry = new RuleRegistry(
        [new ResponseEmpty()],
        severityOverrides: ['response.empty' => 2],
    );

    expect($registry->effectiveLevelFor('response.empty', 0))->toBe(2);
});
