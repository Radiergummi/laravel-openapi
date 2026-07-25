<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\NoneEnvelope;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Extraction\TypedReturnResponseResolver;
use Radiergummi\OpenApi\Support\Generator\BaselineRegistration;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses()->group('openapi');

class PlainReturnAddress
{
    public function __construct(
        public string $street = '',
        public string $city = '',
    ) {}
}

/** A plain POPO — no Data/Resource/Model base, so only the baseline resolver can shape it. */
class PlainReturnDto
{
    public function __construct(
        public string $name = '',
        public int $count = 0,
        public ArticleStatus $status = ArticleStatus::Draft,
        public PlainReturnAddress $address = new PlainReturnAddress(),
        public ?string $note = null,
    ) {}
}

class PlainReturnService
{
    public function handle(): void {}
}

class TypedReturnController extends Controller
{
    /**
     * @return array{id: int, name: string}
     */
    public function arrayShape(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function scalar(): string
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function backedEnum(): ArticleStatus
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return array<string, int>
     */
    public function map(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return list<int>
     */
    public function listOfScalars(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return array{id: int}
     */
    public function nullableShape(): ?array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function scalarUnion(): int|string
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /** @phpstan-ignore missingType.return (an untyped return is the degrade case under test) */
    public function untyped()
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    // A Spatie Data return must stay claimed by the SpatieData plugin, not the baseline.
    public function dataReturn(): ScalarOnlyData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function plainDto(): PlainReturnDto
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return list<PlainReturnDto>
     */
    public function plainDtoList(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function service(): PlainReturnService
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function void(): void
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return array{ok: bool, svc: PlainReturnService}
     */
    public function arrayWithService(): array
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function objectUnion(): PlainReturnDto|PlainReturnAddress
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function nullableDto(): ?PlainReturnDto
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function neverReturn(): never
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @throws NotFoundHttpException
     */
    public function neverNotFound(): never
    {
        throw new NotFoundHttpException('Signature-only fixture; never invoked.');
    }

    #[Response(status: 200, description: 'OK')]
    public function neverWithResponseAttribute(): never
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    #[Response(status: 404, description: 'Not found')]
    public function neverWithErrorResponseAttribute(): never
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    // A resourceful action name on a matching verb, so a convention status (204) would apply,
    // proving suppression skips the convention branch instead of crashing on a null primary.
    public function destroy(): never
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

function typedReturnSchema(string $uri): mixed
{
    return generateSpec()['paths'][$uri]['get']['responses']['200']['content']['application/json']['schema'] ?? null;
}

it('documents a documented array-shape return as an object schema', function (): void {
    Route::get('/typed/array-shape', [TypedReturnController::class, 'arrayShape']);

    $schema = typedReturnSchema('/typed/array-shape');

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['id', 'name'])
        ->and($schema['properties']['id']['type'])->toBe('integer')
        ->and($schema['properties']['name']['type'])->toBe('string')
        ->and($schema['required'])->toEqualCanonicalizing(['id', 'name']);
});

it('documents a scalar return', function (): void {
    Route::get('/typed/scalar', [TypedReturnController::class, 'scalar']);

    expect(typedReturnSchema('/typed/scalar'))->toBe(['type' => 'string']);
});

it('documents a backed-enum return as a component $ref', function (): void {
    Route::get('/typed/enum', [TypedReturnController::class, 'backedEnum']);

    expect(typedReturnSchema('/typed/enum')['$ref'] ?? null)
        ->toBe('#/components/schemas/ArticleStatus');
});

it('documents a string-keyed map return as additionalProperties', function (): void {
    Route::get('/typed/map', [TypedReturnController::class, 'map']);

    $schema = typedReturnSchema('/typed/map');

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBe(['type' => 'integer']);
});

it('documents a list return as an array schema', function (): void {
    Route::get('/typed/list', [TypedReturnController::class, 'listOfScalars']);

    $schema = typedReturnSchema('/typed/list');

    expect($schema['type'])->toBe('array')
        ->and($schema['items'])->toBe(['type' => 'integer']);
});

it('wraps a nullable typed return in the OAS 3.1 nullable idiom', function (): void {
    Route::get('/typed/nullable', [TypedReturnController::class, 'nullableShape']);

    $schema = typedReturnSchema('/typed/nullable');

    expect($schema['oneOf'] ?? null)->toHaveCount(2)
        ->and($schema['oneOf'][1])->toBe(['type' => 'null'])
        ->and($schema['oneOf'][0]['type'])->toBe('object');
});

it('documents a scalar union as oneOf', function (): void {
    Route::get('/typed/union', [TypedReturnController::class, 'scalarUnion']);

    $schema = typedReturnSchema('/typed/union');

    expect($schema['oneOf'] ?? null)->toBe([
        ['type' => 'integer'],
        ['type' => 'string'],
    ]);
});

it('degrades an untyped return to no response body', function (): void {
    Route::get('/typed/untyped', [TypedReturnController::class, 'untyped']);

    $response = generateSpec()['paths']['/typed/untyped']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('leaves a Spatie Data return to the SpatieData plugin, not the baseline', function (): void {
    Route::get('/typed/data', [TypedReturnController::class, 'dataReturn']);

    expect(typedReturnSchema('/typed/data')['$ref'] ?? null)
        ->toBe('#/components/schemas/ScalarOnlyData');
});

it('builds a component schema for a plain typed DTO return', function (): void {
    Route::get('/typed/dto', [TypedReturnController::class, 'plainDto']);

    $spec = generateSpec();
    $schema = $spec['paths']['/typed/dto']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['$ref'] ?? null)->toBe('#/components/schemas/PlainReturnDto');

    $component = $spec['components']['schemas']['PlainReturnDto'] ?? null;

    expect($component)->not->toBeNull()
        ->and($component['type'])->toBe('object')
        ->and($component['properties'])->toHaveKeys(['name', 'count', 'status', 'address', 'note'])
        ->and($component['properties']['name']['type'])->toBe('string')
        ->and($component['properties']['count']['type'])->toBe('integer')
        ->and($component['properties']['status']['$ref'])->toBe('#/components/schemas/ArticleStatus')
        ->and($component['properties']['address']['$ref'])->toBe('#/components/schemas/PlainReturnAddress')
        // A nullable property is not required.
        ->and($component['required'])->not->toContain('note')
        ->and($component['required'])->toContain('name');

    // The nested plain object is pooled as its own component.
    expect($spec['components']['schemas']['PlainReturnAddress']['type'] ?? null)->toBe('object');
});

it('array-wraps a typed collection of plain DTOs', function (): void {
    Route::get('/typed/dto-list', [TypedReturnController::class, 'plainDtoList']);

    $schema = typedReturnSchema('/typed/dto-list');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'] ?? null)->toBe('#/components/schemas/PlainReturnDto');
});

it('degrades a service-object return with no usable properties to no response body', function (): void {
    Route::get('/typed/service', [TypedReturnController::class, 'service']);

    $response = generateSpec()['paths']['/typed/service']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('degrades a void return to no response body', function (): void {
    Route::get('/typed/void', [TypedReturnController::class, 'void']);

    $response = generateSpec()['paths']['/typed/void']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('registers the baseline resolver last, so convention plugins win first', function (): void {
    $resolvers = app(OpenApiRegistry::class)->primaryResponseResolvers;

    expect($resolvers)->not->toBeEmpty()
        ->and($resolvers[count($resolvers) - 1])->toBe(TypedReturnResponseResolver::class)
        // It appears exactly once, and is not the first (a plugin resolver precedes it).
        ->and(array_keys($resolvers, TypedReturnResponseResolver::class, true))->toHaveCount(1)
        ->and($resolvers[0])->not->toBe(TypedReturnResponseResolver::class);
});

it('leaves a nested unbuildable object field unconstrained inside an array shape, never stubbed', function (): void {
    Route::get('/typed/array-service', [TypedReturnController::class, 'arrayWithService']);

    $schema = typedReturnSchema('/typed/array-service');

    // The service field has no baseline schema, so it stays unconstrained ({}), never the
    // engine's "Unmapped object type" string stub.
    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['ok']['type'])->toBe('boolean')
        ->and($schema['properties']['svc'])->toBe([]);
});

it('documents an object union return as a oneOf of component refs', function (): void {
    Route::get('/typed/object-union', [TypedReturnController::class, 'objectUnion']);

    $schema = typedReturnSchema('/typed/object-union');

    // Union members are normalized (sorted) by the type engine, so assert the set, not the order.
    $refs = array_map(static fn(array $member): string => $member['$ref'], $schema['oneOf'] ?? []);

    expect($refs)->toEqualCanonicalizing([
        '#/components/schemas/PlainReturnDto',
        '#/components/schemas/PlainReturnAddress',
    ]);
});

it('wraps a nullable plain-DTO return in the OAS 3.1 nullable idiom', function (): void {
    Route::get('/typed/nullable-dto', [TypedReturnController::class, 'nullableDto']);

    $schema = typedReturnSchema('/typed/nullable-dto');

    expect($schema['oneOf'] ?? null)->toHaveCount(2)
        ->and($schema['oneOf'][0]['$ref'])->toBe('#/components/schemas/PlainReturnDto')
        ->and($schema['oneOf'][1])->toBe(['type' => 'null']);
});

it('suppresses the synthetic 200 for a never return, documenting a default response instead', function (): void {
    Route::get('/typed/never', [TypedReturnController::class, 'neverReturn']);

    $responses = generateSpec()['paths']['/typed/never']['get']['responses'] ?? [];

    // The action cannot succeed, so no 2xx is documented (and no synthetic empty 200); the
    // catch-all default carries the outcome so the operation still documents a response.
    $successStatuses = array_filter(
        array_keys($responses),
        static fn(int|string $status): bool => (int) $status >= 200 && (int) $status <= 299,
    );

    expect($successStatuses)->toBe([])
        ->and($responses)->toHaveKey('default')
        ->and($responses['default'])->toBe([
            'description' => 'The action never returns a successful response.',
        ]);
});

it('keeps the inferred error responses alongside the default for a never return', function (): void {
    Route::get('/typed/never-throws', [TypedReturnController::class, 'neverNotFound']);

    $responses = generateSpec()['paths']['/typed/never-throws']['get']['responses'] ?? [];

    expect($responses)->toHaveKey('default')
        ->and($responses)->toHaveKey('404')
        ->and($responses)->not->toHaveKey('200');
});

it('emits a spec-valid document for a never return', function (): void {
    Route::get('/typed/never-valid', [TypedReturnController::class, 'neverReturn']);
    app()->forgetScopedInstances();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['spec.invalid']));

    expect($result->findings)->toBe([]);
});

it('does not emit response.no-success for a never return, with or without inferred errors', function (): void {
    Route::get('/typed/never-nosuccess', [TypedReturnController::class, 'neverReturn']);
    Route::get('/typed/never-nosuccess-throws', [TypedReturnController::class, 'neverNotFound']);
    app()->forgetScopedInstances();

    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['response.no-success'],
        uriGlob: 'typed/never-nosuccess*',
    ));

    expect($result->findings)->toBe([]);
});

it('lets an explicit 2xx #[Response] win over never suppression', function (): void {
    Route::get('/typed/never-explicit', [TypedReturnController::class, 'neverWithResponseAttribute']);

    $response = generateSpec()['paths']['/typed/never-explicit']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('OK');
});

it('keeps the default floor when a never action carries a non-2xx #[Response]', function (): void {
    Route::get('/typed/never-error-attribute', [TypedReturnController::class, 'neverWithErrorResponseAttribute']);

    $responses = generateSpec()['paths']['/typed/never-error-attribute']['get']['responses'] ?? [];

    // Only a 2xx attribute overrides the primary, so a documented 404 leaves the action suppressed:
    // it still has no success response, and the floor documents that.
    expect($responses)->toHaveKey('default')
        ->and($responses)->toHaveKey('404')
        ->and($responses)->not->toHaveKey('200');
});

it('does not emit operation.return-type-missing for a never return (suppression is intentional)', function (): void {
    Route::get('/typed/never-lint', [TypedReturnController::class, 'neverReturn']);
    app()->forgetScopedInstances();

    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['operation.return-type-missing'],
        uriGlob: 'typed/never-lint',
    ));

    expect($result->findings)->toBe([]);
});

it('skips the convention status for a never return without crashing on a null primary', function (): void {
    // `destroy` on DELETE resolves a 204 convention status; suppression must skip that branch.
    Route::delete('/typed/never-destroy', [TypedReturnController::class, 'destroy']);

    $responses = generateSpec()['paths']['/typed/never-destroy']['delete']['responses'] ?? [];

    expect($responses)->not->toHaveKey('204')
        ->and($responses)->not->toHaveKey('200');
});

it('shapes a plain DTO with every convention plugin disabled (language-level path)', function (): void {
    // Rebind the registry with no plugins at all — not even Core — to prove the baseline resolver is
    // Support-level and independent of any convention plugin.
    app()->scoped(
        OpenApiRegistry::class,
        static fn(Container $app): OpenApiRegistry => BaselineRegistration::assemble(
            $app,
            plugins: [],
            configRules: [],
            errorEnvelopeResolver: NoneEnvelope::class,
        ),
    );

    Route::get('/typed/dto-core-off', [TypedReturnController::class, 'plainDto']);

    $schema = typedReturnSchema('/typed/dto-core-off');

    expect($schema['$ref'] ?? null)->toBe('#/components/schemas/PlainReturnDto');
});
