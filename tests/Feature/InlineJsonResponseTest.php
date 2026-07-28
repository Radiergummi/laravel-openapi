<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonWithAttributeController;
use Radiergummi\OpenApi\Tests\Fixtures\ResourceDestroyResetContentController;

use function array_any;
use function str_contains;

uses()->group('openapi');

#[ResourceField('id', type: 'integer')]
class InlineJsonProbeResource extends JsonResource {}

class InlineJsonResourceAuthoredController extends Controller
{
    #[ResponseResource(InlineJsonProbeResource::class)]
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->buildResource()]);
    }

    /** @return array<string, mixed> */
    private function buildResource(): array
    {
        return [];
    }
}

// region Inferred responses

it('emits an object response schema from a literal response()->json() body', function (): void {
    Route::get('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'literalObject']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/inline-json']['get']['responses']['200'];

    expect($response['description'])
        ->toBe('OK')
        ->and($response['content']['application/json']['schema']['type'])->toBe('object')
        ->and($response['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['message', 'success', 'attempts', 'score'])
        ->and($response['content']['application/json']['schema']['properties']['message']['type'])->toBe('string');
});

it('documents the body under the literal status argument', function (): void {
    Route::post('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'literalStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/inline-json']['post']['responses'];

    expect($responses)
        ->toHaveKey('201')
        ->and($responses['201']['description'])->toBe('Created')
        ->and($responses['201']['content']['application/json']['schema']['properties'])->toHaveKey('id');
});

it('keeps a partial literal property as an unconstrained schema end to end', function (): void {
    Route::get('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'partialLiteral']);

    $spec = generateSpec();
    $properties = $spec['paths']['/oa-fixture/inline-json']['get']['responses']['200']
    ['content']['application/json']['schema']['properties'];

    expect($properties)
        ->toHaveKeys(['logs', 'success'])
        ->and($properties['logs'])->toBe([])
        ->and($properties['success']['type'])->toBe('boolean');
});

it('resolves named data and status arguments', function (): void {
    Route::post('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'namedArguments']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/inline-json']['post']['responses'];

    expect($responses)
        ->toHaveKey('201')
        ->and($responses['201']['content']['application/json']['schema']['properties'])->toHaveKey('queued');
});

// endregion

// region Precedence

it('prefers an explicit #[Response] attribute over the inferred body', function (): void {
    Route::get('/oa-fixture/attributed', [InlineJsonWithAttributeController::class, 'show']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/attributed']['get']['responses']['200'];

    expect($response['description'])
        ->toBe('Authored response that must win')
        ->and($response['content']['application/json']['schema']['properties'])->toHaveKey('authored')
        ->and($response['content']['application/json']['schema']['properties'])->not->toHaveKey('inferred');
});

it('prefers the typed return value over the body scan', function (): void {
    Route::get('/oa-fixture/typed', [InlineJsonFixtureController::class, 'typedReturnWithJsonBody']);

    $spec = generateSpec();
    $schema = $spec['paths']['/oa-fixture/typed']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['$ref'])
        ->toBe('#/components/schemas/Article')
        ->and($schema)->not->toHaveKey('properties');
});

it('lets an explicit #[ResponseResource] win over the literal json envelope', function (): void {
    Route::get('/oa-fixture/resource-authored', [InlineJsonResourceAuthoredController::class, 'show']);

    $spec = generateSpec();
    $schema = $spec['paths']['/oa-fixture/resource-authored']['get']['responses']['200']
    ['content']['application/json']['schema'];

    // The ApiResources resolver (later in the chain) consumes the attribute; the body scan must
    // step aside instead of documenting the partial `{data: {}}` literal.
    expect($schema['properties']['data']['$ref'])
        ->toBe('#/components/schemas/InlineJsonProbeResource')
        ->and($spec['components']['schemas'])->toHaveKey('InlineJsonProbeResource');
});

it('does not let a straight-line non-2xx literal evict the success response', function (): void {
    Route::post('/oa-fixture/guarded', [InlineJsonFixtureController::class, 'guardedSuccessWithTerminalError']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/guarded']['post']['responses'];

    // The guarded-success + terminal-403-fallback idiom: the operation keeps its (bare) success
    // response, and the terminal error literal is routed into a 403 error response by the error
    // machinery (#238) — the primary scan no longer evicts the success slot with it.
    expect($responses)
        ->toHaveKey('200')
        ->and($responses)->toHaveKey('403');
});

// endregion

it('produces a swagger-php-valid document for an unreadable list body (#265)', function (): void {
    Route::get('/oa-fixture/unreadable-list', [InlineJsonFixtureController::class, 'unreadableListBody']);

    // The real `openapi:generate` validates the document (GenerateCommand::validate); generateSpec()
    // does not. swagger-php rejects a `type: array` without `items`, so this guards the actual
    // command path for the items-less-array crash.
    $registry = app(SpecRegistry::class);
    $document = app(OpenApiGenerator::class)
        ->generate($registry->default(), app()->environment());

    expect($document->validate())->toBeTrue();

    $items = generateSpec()['paths']['/oa-fixture/unreadable-list']['get']['responses']['200']
    ['content']['application/json']['schema']['properties']['items'];

    expect($items['type'])
        ->toBe('array')
        ->and($items)->toHaveKey('items');
});

// region Degradation

it('falls back to the bare 200 and logs a note for a variable body', function (): void {
    Route::get('/oa-fixture/variable', [InlineJsonFixtureController::class, 'variableBody']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/variable']['get']['responses']['200'];

    expect($response)->not->toHaveKey('content');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'no statically readable body'),
    );

    expect($noted)->toBeTrue();
});

// endregion

// region Contentless statuses

it('documents a literal 205 without the body the call writes', function (): void {
    Route::get('/oa-fixture/reset-content', [InlineJsonFixtureController::class, 'resetContentStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/reset-content']['get']['responses'];

    expect($responses)->toHaveKey('205')
        ->and($responses['205'])->not->toHaveKey('content')
        ->and($responses['205']['description'])->toBe('Reset Content');
});

it('documents a constructed JsonResponse 205 without a body', function (): void {
    Route::get('/oa-fixture/reset-constructed', [
        InlineJsonFixtureController::class,
        'constructedResetContent',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/reset-constructed']['get']['responses'];

    expect($responses)->toHaveKey('205')
        ->and($responses['205'])->not->toHaveKey('content');
});

it('documents a chained ->setStatusCode(205) without a body', function (): void {
    Route::get('/oa-fixture/reset-chained', [
        InlineJsonFixtureController::class,
        'setStatusCodeResetContent',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/reset-chained']['get']['responses'];

    expect($responses)->toHaveKey('205')
        ->and($responses['205'])->not->toHaveKey('content');
});

it('keeps a resourceful destroy 205 body-less rather than letting the conventional 204 reclaim it', function (): void {
    Route::delete('/oa-fixture/widgets/{widget}', [
        ResourceDestroyResetContentController::class,
        'destroy',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/widgets/{widget}']['delete']['responses'];

    expect($responses)->toHaveKey('205')
        ->and($responses['205'])->not->toHaveKey('content')
        ->and($responses)->not->toHaveKey('204');
});

it('leaves a literal 204 untouched', function (): void {
    // The pre-read gate this change does not move: a 204 is claimed before any body is read.
    Route::get('/oa-fixture/no-content-literal', [InlineJsonFixtureController::class, 'noContentStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/no-content-literal']['get']['responses'];

    expect($responses)->toHaveKey('204')
        ->and($responses['204'])->not->toHaveKey('content')
        ->and($responses['204']['description'])->toBe('No Content');
});

it('still documents the body on a content-bearing 2xx', function (string $action, string $status): void {
    // The guard against swallowing readable bodies: only a contentless status may lose its schema.
    Route::get('/oa-fixture/content-bearing', [InlineJsonFixtureController::class, $action]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/content-bearing']['get']['responses'];

    expect($responses[$status]['content']['application/json']['schema']['type'])->toBe('object');
})->with([
    'literal 200' => ['literalObject', '200'],
    'literal 201' => ['literalStatus', '201'],
    'constructed 201' => ['constructedWithStatus', '201'],
]);

it('still degrades a literal 304 to a body-less conventional 200', function (): void {
    // A 304 forbids content too, but it is refused as a non-2xx error status before the body gates.
    Route::get('/oa-fixture/not-modified', [InlineJsonFixtureController::class, 'notModifiedStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/not-modified']['get']['responses'];

    expect($responses)->toHaveKey('200')
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($responses)->not->toHaveKey('304');
});

// The three rows below are the 205 shapes this change deliberately leaves degrading to a
// conventional 200, because no body was read and an unreadable body is unknown rather than empty.
// #614 owns the widening that would turn them into body-less 205s, so pinning them here makes that
// movement visible instead of silent.
it('leaves a 205 whose body could not be read degrading to a conventional 200', function (
    string $action,
): void {
    Route::get('/oa-fixture/reset-degrade', [InlineJsonFixtureController::class, $action]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/reset-degrade']['get']['responses'];

    expect($responses)->toHaveKey('200')
        ->and($responses['200'])->not->toHaveKey('content')
        ->and($responses)->not->toHaveKey('205');
})->with([
    'empty literal body' => ['emptyResetContent'],
    'unreadable variable body' => ['variableBodyResetContent'],
    'resource argument' => ['resourceResetContent'],
]);

// endregion
