<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UuidArticle;

uses()->group('openapi', 'routing');

/**
 * End-to-end coverage for typing a route-model-bound path parameter from the bound model's key,
 * exercised through the full RouteIntrospector → UriParameterResolver → UriParametersExtractor
 * pipeline. The precedence rules and guard are unit-tested in
 * tests/Unit/Support/Extraction/UriParametersExtractorTest.php.
 */
function pathParameterSchema(array $spec, string $path, string $name): array
{
    return collect($spec['paths'][$path]['get']['parameters'])
        ->firstWhere('name', $name)['schema'];
}

it('types an int-keyed model binding as integer', function (): void {
    Route::get('/articles/{article}', static fn(Article $article): array => []);

    $schema = pathParameterSchema(generateSpec(), '/articles/{article}', 'article');

    expect($schema['type'])->toBe('integer')
        ->and($schema)->not->toHaveKey('format');
});

it('types a HasUuids model binding as a uuid-formatted string', function (): void {
    Route::get('/articles/{article}', static fn(UuidArticle $article): array => []);

    $schema = pathParameterSchema(generateSpec(), '/articles/{article}', 'article');

    expect($schema['type'])->toBe('string')
        ->and($schema['format'])->toBe('uuid');
});

it('keeps a custom-key binding a bare string, not the primary key type', function (): void {
    // {article:slug} binds an int-keyed Article by its `slug`, so the int key type must not leak.
    Route::get('/articles/{article:slug}', static fn(Article $article): array => []);

    $schema = pathParameterSchema(generateSpec(), '/articles/{article}', 'article');

    expect($schema['type'])->toBe('string')
        ->and($schema)->not->toHaveKey('format');
});
