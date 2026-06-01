<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Rules\SpecUnknownReference;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SpecMethodShadowsClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\SpecUnknownRefController;

uses()->group('openapi', 'lint');

function specUnknownRefRegistry(string ...$names): SpecRegistry
{
    $specs = [];

    foreach ($names as $name) {
        if ($name !== 'default') {
            $specs[$name] = [];
        }
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
function specUnknownRefDescriptor(string $controller): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/fixture', []),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, 'handle'),
        summary: null,
        description: null,
    );
}

function collectSpecUnknownReferenceFindings(SpecRegistry $registry, array $descriptors): array
{
    $findings = [];

    $collector = new class ($findings) implements FindingsCollector {
        /** @param list<Finding> $findings */
        public function __construct(public array &$findings) {}

        public function emit(Finding $finding): void
        {
            $this->findings[] = $finding;
        }
    };

    new SpecUnknownReference(new SpecResolver())->checkConfiguration($registry, $descriptors, $collector);

    return $collector->findings;
}

it('has the correct id and level', function (): void {
    $rule = new SpecUnknownReference(new SpecResolver());

    expect($rule->id())->toBe('spec.unknown-reference')
        ->and($rule->level())->toBe(0);
});

it('emits no findings when the descriptors list is empty', function (): void {
    $registry = specUnknownRefRegistry('default');

    expect(collectSpecUnknownReferenceFindings($registry, []))->toBe([]);
});

it('emits no findings when the route carries no #[Spec] attribute', function (): void {
    $registry = specUnknownRefRegistry('default');
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/plain', []),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect(collectSpecUnknownReferenceFindings($registry, [$descriptor]))->toBe([]);
});

it('emits no findings when all referenced spec names are declared', function (): void {
    // SpecUnknownRefController has #[Spec('ghost')] — so we declare 'ghost' in the registry
    $registry = specUnknownRefRegistry('default', 'ghost');
    $descriptor = specUnknownRefDescriptor(SpecUnknownRefController::class);

    expect(collectSpecUnknownReferenceFindings($registry, [$descriptor]))->toBe([]);
});

it('emits a finding when a #[Spec] argument references an undeclared spec name', function (): void {
    // 'ghost' is NOT in the registry — only 'default' is
    $registry = specUnknownRefRegistry('default');
    $descriptor = specUnknownRefDescriptor(SpecUnknownRefController::class);

    $findings = collectSpecUnknownReferenceFindings($registry, [$descriptor]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('spec.unknown-reference')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('ghost');
});

it('does not flag a class-level Spec when a method-level Spec shadows it', function (): void {
    // SpecMethodShadowsClassController: class has #[Spec('ghost')], method has #[Spec('v2')].
    // 'v2' is declared; 'ghost' is not — but SpecResolver discards the class-level attribute
    // when the method carries any #[Spec], so the rule must not fire on 'ghost'.
    $registry = specUnknownRefRegistry('default', 'v2');
    $descriptor = specUnknownRefDescriptor(SpecMethodShadowsClassController::class);

    expect(collectSpecUnknownReferenceFindings($registry, [$descriptor]))->toBe([]);
});
