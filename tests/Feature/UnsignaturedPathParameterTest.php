<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses()->group('openapi', 'routing');

/**
 * Coverage for emitting a path parameter for every URI placeholder, including those that are not a
 * typed controller argument (invokable controllers, `Request`-only actions, the parent of a
 * scoped/nested binding). Such placeholders default to `type: string` and are enriched from
 * `where*` constraints. Exercised through the full RouteIntrospector → UriParameterResolver →
 * UriParametersExtractor pipeline.
 */
function pathParameter(array $spec, string $path, string $name): array
{
    return collect($spec['paths'][$path]['get']['parameters'])
        ->firstWhere('name', $name);
}

it('emits both a typed and an unsignatured placeholder for /{a}/{b}', function (): void {
    Route::get('/things/{a}/{b}', static fn(int $b): array => []);

    $spec = generateSpec();
    $parameters = collect($spec['paths']['/things/{a}/{b}']['get']['parameters']);

    $a = $parameters->firstWhere('name', 'a');
    $b = $parameters->firstWhere('name', 'b');

    expect($a)->not->toBeNull()
        ->and($a['in'])->toBe('path')
        ->and($a['required'])->toBeTrue()
        ->and($a['schema']['type'])->toBe('string')
        ->and($b)->not->toBeNull()
        ->and($b['in'])->toBe('path')
        ->and($b['required'])->toBeTrue()
        ->and($b['schema']['type'])->toBe('integer');

    // Signature-derived params come first, then undeclared placeholders in URI order.
    expect($parameters->pluck('name')->all())->toBe(['b', 'a']);
});

it('emits placeholders for an invokable controller with no bound args', function (): void {
    Route::get('/teams/{team}/members/{member}', InvokableTeamMemberController::class);

    $spec = generateSpec();

    $team = pathParameter($spec, '/teams/{team}/members/{member}', 'team');
    $member = pathParameter($spec, '/teams/{team}/members/{member}', 'member');

    expect($team['in'])->toBe('path')
        ->and($team['required'])->toBeTrue()
        ->and($team['schema']['type'])->toBe('string')
        ->and($member['schema']['type'])->toBe('string');
});

it('emits placeholders for a Request-only action', function (): void {
    Route::get('/teams/{team}/members/{member}', static fn(Request $request): array => []);

    $spec = generateSpec();

    $team = pathParameter($spec, '/teams/{team}/members/{member}', 'team');
    $member = pathParameter($spec, '/teams/{team}/members/{member}', 'member');

    expect($team['schema']['type'])->toBe('string')
        ->and($member['schema']['type'])->toBe('string');
});

it('emits the parent of a scoped binding as a string path param', function (): void {
    // Classic scoped-binding parent: only the child {member} is signatured.
    Route::get('/teams/{team}/members/{member}', static fn(string $member): array => []);

    $spec = generateSpec();

    $team = pathParameter($spec, '/teams/{team}/members/{member}', 'team');

    expect($team)->not->toBeNull()
        ->and($team['schema']['type'])->toBe('string');
});

it('enriches an unsignatured uuid-constrained placeholder', function (): void {
    Route::get('/items/{id}', static fn(): array => [])->whereUuid('id');

    $schema = pathParameter(generateSpec(), '/items/{id}', 'id')['schema'];

    expect($schema['format'])->toBe('uuid');
});

it('enriches an unsignatured number-constrained placeholder', function (): void {
    Route::get('/items/{id}', static fn(): array => [])->whereNumber('id');

    $schema = pathParameter(generateSpec(), '/items/{id}', 'id')['schema'];

    expect($schema['type'])->toBe('integer');
});

it('enriches an unsignatured whereIn-constrained placeholder', function (): void {
    Route::get('/items/{status}', static fn(): array => [])->whereIn('status', ['draft', 'published']);

    $schema = pathParameter(generateSpec(), '/items/{status}', 'status')['schema'];

    expect($schema['enum'])->toBe(['draft', 'published']);
});

it('enriches an unsignatured custom-regex placeholder', function (): void {
    Route::get('/items/{slug}', static fn(): array => [])->where('slug', '[a-z-]+');

    $schema = pathParameter(generateSpec(), '/items/{slug}', 'slug')['schema'];

    expect($schema['pattern'])->toBe('[a-z-]+');
});

it('emits the bare name for an unsignatured {param:field} placeholder', function (): void {
    // {member:slug} binds by the `slug` field; the URI template variable is the bare `member`, and
    // the where lookup is keyed on the bare name too — so enrichment must still fire.
    Route::get('/teams/{member:slug}', static fn(): array => [])->whereUuid('member');

    $spec = generateSpec();
    $member = pathParameter($spec, '/teams/{member}', 'member');

    expect($member)->not->toBeNull()
        ->and($member['name'])->toBe('member')
        ->and($member['schema']['format'])->toBe('uuid');
});

it('does not duplicate a fully-signatured route placeholder', function (): void {
    Route::get('/items/{id}', static fn(int $id): array => []);

    $parameters = collect(generateSpec()['paths']['/items/{id}']['get']['parameters'])
        ->where('name', 'id');

    expect($parameters)->toHaveCount(1)
        ->and($parameters->first()['schema']['type'])->toBe('integer');
});

it('emits an optional unsignatured placeholder as required with the optional note', function (): void {
    Route::get('/items/{slug?}', static fn(): array => []);

    $slug = pathParameter(generateSpec(), '/items/{slug?}', 'slug');

    expect($slug['required'])->toBeTrue()
        ->and($slug['description'])->toContain('Optional in URL');
});

it('documents the unsignatured parent of a scoped binding so the linter has nothing to flag', function (): void {
    // #416 exists because the generator and linter disagreed: the linter flagged {team} as an
    // undeclared path parameter the generator never emitted. Asserting the parameter is present in
    // the generated spec is the regression guard (it fails on the pre-fix code, where {team} was
    // dropped); the path.parameter-undeclared rule then has nothing to report.
    Route::get('/teams/{team}/members/{member}', static fn(string $member): array => []);

    $team = pathParameter(generateSpec(), '/teams/{team}/members/{member}', 'team');

    expect($team)->not->toBeNull()
        ->and($team['in'])->toBe('path')
        ->and($team['required'])->toBeTrue();
});

final class InvokableTeamMemberController
{
    public function __invoke(): array
    {
        return [];
    }
}
