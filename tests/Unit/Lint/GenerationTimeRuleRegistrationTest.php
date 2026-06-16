<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Lint\Rules\RequestEmpty;
use Radiergummi\OpenApi\Plugins\Core\Lint\RuleUnknown;
use Radiergummi\OpenApi\Plugins\Core\Lint\ThrowsUnmapped;

uses()->group('openapi', 'lint');

/**
 * Build a RuleRegistry containing only the core generation-time stub rules.
 *
 * We instantiate the stubs directly rather than going through CoreRegistration and OpenApiRegistry,
 * because some other core rules (e.g., SpecInvalid) call resource_path() at construction time and
 * require a full Laravel application. Unit tests run under a bare container and cannot satisfy
 * that requirement.
 */
function buildRegistry(): RuleRegistry
{
    return new RuleRegistry([
        new RequestEmpty(),
        new ThrowsUnmapped(),
        new RuleUnknown(),
    ]);
}

// region Known IDs — generation-time core findings

it('registers request.empty in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('request.empty');
});

it('registers throws.unmapped in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('throws.unmapped');
});

it('registers rule.unknown in knownIds', function (): void {
    expect(buildRegistry()->knownIds())->toContain('rule.unknown');
});

// endregion

// region All core generation-time IDs present in a single knownIds() call

it('contains all core generation-time IDs in knownIds', function (): void {
    $ids = buildRegistry()->knownIds();

    expect($ids)
        ->toContain('request.empty')
        ->toContain('throws.unmapped')
        ->toContain('rule.unknown');
});

// endregion

// region Severity override integration — registry can remap generation-time findings

it('severity override applies to request.empty', function (): void {
    $registry = new RuleRegistry(
        [new RequestEmpty()],
        severityOverrides: ['request.empty' => 2],
    );

    // With the override in place, effectiveLevelFor returns the remapped severity regardless of
    // the caller's fallback.
    expect($registry->effectiveLevelFor('request.empty', Severity::Broken))
        ->toBe(Severity::Underspecified);
});

// endregion
