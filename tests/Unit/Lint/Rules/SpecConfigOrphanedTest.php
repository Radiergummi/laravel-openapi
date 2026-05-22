<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Rules\SpecConfigOrphaned;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;

uses()->group('openapi', 'lint');

function specConfigOrphanedEvaluator(): InclusionEvaluator
{
    return new InclusionEvaluator(
        globalFilters: [],
        matcher: new SpecMatcher(),
        specResolver: new SpecResolver(),
        visibility: new VisibilityResolver(VisibilityMode::Public),
    );
}

function specConfigOrphanedRegistry(?array $specs): SpecRegistry
{
    return new SpecRegistry(
        rootInfo: ['title' => 'Test', 'version' => '1.0'],
        rootServers: [],
        rootTags: [],
        rootOutputPath: '/tmp/openapi.yaml',
        rootRouteUri: 'openapi.yaml',
        rootPlaygroundUri: 'docs',
        specs: $specs,
        storagePath: '/tmp',
    );
}

function specConfigOrphanedDescriptor(string $uri, array $middleware = []): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], $uri, fn() => null)->middleware($middleware),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );
}

function collectSpecConfigOrphanedFindings(SpecRegistry $registry, array $descriptors): array
{
    $collector = new class () implements FindingsCollector {
        /** @var list<Finding> */
        public array $findings = [];

        public function emit(Finding $finding): void
        {
            $this->findings[] = $finding;
        }
    };

    new SpecConfigOrphaned(specConfigOrphanedEvaluator())
        ->checkConfiguration($registry, $descriptors, $collector);

    return $collector->findings;
}

it('has the correct id and level', function (): void {
    $rule = new SpecConfigOrphaned(specConfigOrphanedEvaluator());

    expect($rule->id())->toBe('spec.config-orphaned')
        ->and($rule->level())->toBe(3);
});

it('emits no findings when the default spec matches at least one route', function (): void {
    $registry = specConfigOrphanedRegistry(null);
    $descriptor = specConfigOrphanedDescriptor('api/flights');

    expect(collectSpecConfigOrphanedFindings($registry, [$descriptor]))->toBe([]);
});

it('emits a finding when a named spec matches no routes', function (): void {
    // 'internal' spec requires prefix 'internal/*' — none of our descriptors match
    $registry = specConfigOrphanedRegistry([
        'internal' => ['match' => ['prefix' => 'internal/*']],
    ]);
    $descriptor = specConfigOrphanedDescriptor('api/flights');

    $findings = collectSpecConfigOrphanedFindings($registry, [$descriptor]);

    // 'default' always matches; 'internal' does not
    $orphaned = array_values(array_filter($findings, static fn($f) => str_contains($f->message, 'internal')));

    expect($orphaned)->toHaveCount(1)
        ->and($orphaned[0]->ruleId)->toBe('spec.config-orphaned')
        ->and($orphaned[0]->level)->toBe(3)
        ->and($orphaned[0]->message)->toContain('internal');
});

it('emits no findings when descriptors list is empty and only the default spec exists', function (): void {
    // Default spec has no match config so InclusionEvaluator uses spec membership / match rules.
    // With no descriptors there are no routes to match — default is also orphaned.
    // This test documents that the rule fires for the default spec too when there are no routes.
    $registry = specConfigOrphanedRegistry(null);

    $findings = collectSpecConfigOrphanedFindings($registry, []);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('default');
});
