<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\UntypedReturnResource;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

use function array_any;
use function iterator_to_array;
use function random_int;
use function str_contains;

uses()->group('openapi', 'plugin:api-resources');

/**
 * Reproduces the AureusERP/Koel pattern: actions return an API Resource but declare no return type
 * (relying on convention or a third-party doc attribute the library does not read). The concrete
 * resource is only recoverable from the method body. Actions are never invoked, only parsed.
 */
class UntypedReturnController extends Controller
{
    public function index()
    {
        return UntypedReturnResource::collection(Author::query()->paginate());
    }

    public function indexUnpaginated()
    {
        return UntypedReturnResource::collection(Author::all());
    }

    public function show()
    {
        return new UntypedReturnResource(Author::query()->firstOrFail());
    }

    public function make()
    {
        return UntypedReturnResource::make(Author::query()->firstOrFail());
    }

    public function destroy()
    {
        return response()->json(['ok' => true]);
    }

    public function untypedConditional(bool $flag)
    {
        if ($flag) {
            return UntypedReturnResource::make(Author::query()->firstOrFail());
        }

        return new UntypedReturnResource(Author::query()->firstOrFail());
    }

    public function untypedScalar()
    {
        return random_int(0, 1);
    }
}

/**
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function untypedSuccessSchema(array $spec, string $path): array
{
    $schema = $spec['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull();

    return $schema;
}

// region Resolved shapes

it('resolves a paginated collection from an untyped index() action', function (): void {
    Route::get('/untyped-authors', [UntypedReturnController::class, 'index']);

    $spec = generateSpec();
    $schema = untypedSuccessSchema($spec, '/untyped-authors');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/UntypedReturnResource')
        ->and($spec['components']['schemas']['UntypedReturnResource']['properties'])->toHaveKeys(['id', 'title']);
});

it('resolves a plain {data} envelope from an untyped unpaginated collection', function (): void {
    Route::get('/untyped-authors-unpaginated', [UntypedReturnController::class, 'indexUnpaginated']);

    $schema = untypedSuccessSchema(generateSpec(), '/untyped-authors-unpaginated');

    expect($schema['properties'])->toHaveKey('data')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/UntypedReturnResource');
});

it('resolves a single resource from an untyped show() returning new X()', function (): void {
    Route::get('/untyped-author', [UntypedReturnController::class, 'show']);

    $schema = untypedSuccessSchema(generateSpec(), '/untyped-author');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/UntypedReturnResource');
});

it('resolves a single resource from an untyped X::make() action', function (): void {
    Route::get('/untyped-author-make', [UntypedReturnController::class, 'make']);

    $schema = untypedSuccessSchema(generateSpec(), '/untyped-author-make');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/UntypedReturnResource');
});

// endregion

// region Negatives & silent degradation

it('does not resolve a resource for an untyped response()->json() action and emits no refusal notice', function (): void {
    Route::get('/untyped-destroy', [UntypedReturnController::class, 'destroy']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $schema = untypedSuccessSchema($spec, '/untyped-destroy');

    // Core's inline response()->json() reader still types the literal body; the ApiResources
    // locator must not turn the untyped action into a resource schema.
    expect($schema['properties'] ?? [])->toHaveKey('ok')
        ->and($schema)->not->toHaveKey('$ref')
        ->and($spec['components']['schemas'] ?? [])->not->toHaveKey('UntypedReturnResource');

    // Silent fallback on the untyped path: the high-volume non-resource case must not log.
    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'destroy'),
    );

    expect($noted)->toBeFalse();
});

it('resolves an untyped action whose two returns are the same resource without a refusal notice', function (): void {
    Route::get('/untyped-conditional', [UntypedReturnController::class, 'untypedConditional']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $schema = untypedSuccessSchema(generateSpec(), '/untyped-conditional');

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/UntypedReturnResource');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'untypedConditional'),
    );

    expect($noted)->toBeFalse();
});

it('leaves an untyped scalar-return action as no content without a refusal notice', function (): void {
    Route::get('/untyped-scalar', [UntypedReturnController::class, 'untypedScalar']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/untyped-scalar']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');

    // A scalar return legitimately has no body, so the resource reader must emit no refusal notice
    // (a NOTICE-level log). The info-level operation.return-type-missing finding, emitted at
    // generation for the untyped return, is a separate channel and is expected here.
    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => $record['level'] === LogLevel::NOTICE
            && str_contains($record['message'], 'untypedScalar'),
    );

    expect($noted)->toBeFalse();
});

// endregion

// region Lint-rule delta

it('does not flag resource.response-ambiguous for an untyped non-resource action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        UntypedReturnController::class,
        'destroy',
        '/untyped-destroy',
    );

    $rule = new ResourceResponseAmbiguous(ResourceClassLocator::create());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

it('does not flag resource.response-ambiguous for an untyped body-resolved collection', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        UntypedReturnController::class,
        'index',
        '/untyped-authors',
    );

    $rule = new ResourceResponseAmbiguous(ResourceClassLocator::create());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toBe([]);
});

// endregion
