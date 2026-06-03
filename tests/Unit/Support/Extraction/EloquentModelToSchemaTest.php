<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi');

/**
 * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
 *
 * @return array<string, mixed>
 */
function readModelSchema(string $modelClass): array
{
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $reader = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
    );

    $key = $reader->build($modelClass);

    /** @var OA\Schema $schema */
    $schema = collect($registry->all())->firstWhere('schema', $key);

    return json_decode(json_encode($schema), associative: true);
}

it('maps datetime casts to string/date-time', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['published_at']['type'])->toBe('string')
        ->and($schema['properties']['published_at']['format'])->toBe('date-time');
});

it('excludes $hidden fields', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties'])->not->toHaveKey('internal_notes');
});

it('includes $appends names in the property set', function (): void {
    $schema = readModelSchema(Article::class);

    expect($schema['properties'])->toHaveKey('reading_time');
});
