<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Core;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

uses()->group('openapi');

class FindReturnModelController extends Controller
{
    /**
     * Untyped on purpose: the native return type stays absent so the body scan fires; the PHPDoc
     * only satisfies the linter (it is not read by the reflection resolver).
     *
     * @return null|Article
     */
    public function find(string $article)
    {
        return Article::find($article);
    }

    /**
     * @return Article
     */
    public function findOrFail(string $article)
    {
        return Article::findOrFail($article);
    }
}

it('infers a 200 model $ref from a directly-returned find()', function (): void {
    Route::get('/articles/{article}', [FindReturnModelController::class, 'find']);

    $operation = generateSpec()['paths']['/articles/{article}']['get'];
    $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['$ref'] ?? null)->toContain('Article');
});

it('composes the findOrFail 200 model schema with the inferred 404', function (): void {
    // findOrFail() yields BOTH a 404 (FindOrFailErrorContributor, #168) and a 200 model schema
    // (this resolver, #97) on the same operation — different machineries, no dedup needed.
    Route::get('/articles/{article}', [FindReturnModelController::class, 'findOrFail']);

    $responses = generateSpec()['paths']['/articles/{article}']['get']['responses'];

    expect($responses)->toHaveKeys(['200', '404'])
        ->and($responses['200']['content']['application/json']['schema']['$ref'] ?? null)
        ->toContain('Article');
});
