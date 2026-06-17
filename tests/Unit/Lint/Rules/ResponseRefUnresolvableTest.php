<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Rules\ResponseRefUnresolvable;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\UnresolvableResponseRefController;

uses()->group('openapi', 'lint');

/**
 * A fake resolver that handles exactly the class names it is given, side-effect-free.
 *
 * @param list<class-string> $handled
 */
function fakeRefResolver(array $handled): RefSchemaResolver
{
    return new class ($handled) implements RefSchemaResolver {
        /** @param list<class-string> $handled */
        public function __construct(private array $handled) {}

        public function canResolve(string $class): bool
        {
            return in_array($class, $this->handled, strict: true);
        }

        public function resolveRef(string $class): ?string
        {
            return $this->canResolve($class) ? "#/components/schemas/{$class}" : null;
        }
    };
}

function responseRefRegistry(): SpecRegistry
{
    return new SpecRegistry(
        rootInfo: ['title' => 'Test', 'version' => '1.0'],
        rootServers: [],
        rootTags: [],
        rootOutputPath: '/tmp/openapi.yaml',
        rootRouteUri: 'openapi.yaml',
        rootPlaygroundUri: 'docs',
        specs: null,
        storagePath: '/tmp',
    );
}

/**
 * @throws ReflectionException
 */
function responseRefDescriptor(string $method, string $controller = UnresolvableResponseRefController::class): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/fixture', []),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

/**
 * @param list<RefSchemaResolver> $resolvers
 * @param list<ActionDescriptor>  $descriptors
 *
 * @return list<Finding>
 */
function collectResponseRefUnresolvableFindings(array $resolvers, array $descriptors): array
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

    new ResponseRefUnresolvable($resolvers)->checkConfiguration(responseRefRegistry(), $descriptors, $collector);

    return $collector->findings;
}

it('has the correct id and level', function (): void {
    $rule = new ResponseRefUnresolvable([]);

    expect($rule->id())->toBe('response.ref-unresolvable')
        ->and($rule->severity())->toBe(Severity::Broken);
});

it('emits no findings when the descriptors list is empty', function (): void {
    expect(collectResponseRefUnresolvableFindings([fakeRefResolver([])], []))->toBe([]);
});

it('emits no findings when a #[Response] carries no ref', function (): void {
    $descriptor = responseRefDescriptor('withoutRef');

    expect(collectResponseRefUnresolvableFindings([fakeRefResolver([])], [$descriptor]))->toBe([]);
});

it('emits no findings when a registered resolver can resolve the ref', function (): void {
    $descriptor = responseRefDescriptor('withResolvableRef');
    $resolvers = [fakeRefResolver([stdClass::class])];

    expect(collectResponseRefUnresolvableFindings($resolvers, [$descriptor]))->toBe([]);
});

it('emits a finding when no registered resolver can resolve the ref', function (): void {
    $descriptor = responseRefDescriptor('withUnresolvableRef');
    $resolvers = [fakeRefResolver([stdClass::class])];

    $findings = collectResponseRefUnresolvableFindings($resolvers, [$descriptor]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.ref-unresolvable')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain(ArrayObject::class)
        ->and($findings[0]->message)->toContain('422');
});

it('emits a finding when there are no resolvers at all', function (): void {
    $descriptor = responseRefDescriptor('withResolvableRef');

    $findings = collectResponseRefUnresolvableFindings([], [$descriptor]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.ref-unresolvable');
});
