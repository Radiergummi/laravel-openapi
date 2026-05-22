<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Rules\SpecRouteOrphaned;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SpecRouteOrphanedController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SpecUnknownRefController;

uses()->group('openapi', 'lint');

function specRouteOrphanedRegistry(string ...$extraNames): SpecRegistry
{
    $specs = [];

    foreach ($extraNames as $name) {
        $specs[$name] = [];
    }

    return new SpecRegistry(
        rootInfo: ['title' => 'Test', 'version' => '1.0'],
        rootServers: [],
        rootTags: [],
        rootOutputPath: '/tmp/openapi.yaml',
        rootRouteUri: 'openapi.yaml',
        rootPlaygroundUri: 'docs',
        specs: $specs === [] ? null : $specs,
        storagePath: '/tmp',
    );
}

/**
 * @throws ReflectionException
 */
function specRouteOrphanedDescriptor(string $controller): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/fixture', []),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, 'handle'),
        summary: null,
        description: null,
    );
}

function collectSpecRouteOrphanedFindings(SpecRegistry $registry, array $descriptors): array
{
    $collector = new class () implements FindingsCollector {
        /** @var list<Finding> */
        public array $findings = [];

        public function emit(Finding $finding): void
        {
            $this->findings[] = $finding;
        }
    };

    new SpecRouteOrphaned()->checkConfiguration($registry, $descriptors, $collector);

    return $collector->findings;
}

it('has the correct id and level', function (): void {
    $rule = new SpecRouteOrphaned();

    expect($rule->id())->toBe('spec.route-orphaned')
        ->and($rule->level())->toBe(0);
});

it('emits no findings when the descriptors list is empty', function (): void {
    $registry = specRouteOrphanedRegistry();

    expect(collectSpecRouteOrphanedFindings($registry, []))->toBe([]);
});

it('emits no findings when the route carries no #[Spec] attribute', function (): void {
    $registry = specRouteOrphanedRegistry();
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/plain', []),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect(collectSpecRouteOrphanedFindings($registry, [$descriptor]))->toBe([]);
});

it('emits no findings when at least one #[Spec] name is declared', function (): void {
    // SpecUnknownRefController has #[Spec('ghost')] — declare 'ghost' so it resolves
    $registry = specRouteOrphanedRegistry('ghost');
    $descriptor = specRouteOrphanedDescriptor(SpecUnknownRefController::class);

    expect(collectSpecRouteOrphanedFindings($registry, [$descriptor]))->toBe([]);
});

it('emits a finding when every #[Spec] name is absent from the registry', function (): void {
    // SpecRouteOrphanedController has #[Spec('nowhere')] — not declared anywhere
    $registry = specRouteOrphanedRegistry();
    $descriptor = specRouteOrphanedDescriptor(SpecRouteOrphanedController::class);

    $findings = collectSpecRouteOrphanedFindings($registry, [$descriptor]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('spec.route-orphaned')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('not appear anywhere');
});
