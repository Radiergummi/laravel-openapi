<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\AbstractModel;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\CustomPrimaryKeyArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\SoftDeletingArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UntimestampedArticle;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

/**
 * Builds the model's response schema and returns the live OA\Schema (so readOnly is visible on the
 * object before swagger-php's array down-conversion). Self-contained to stay parallel-safe.
 *
 * @param class-string<Model> $modelClass
 */
function buildReadOnlyModelSchema(string $modelClass): OA\Schema
{
    $registry = new ComponentSchemaRegistry();
    $logger = new NullLogger();

    $reader = new EloquentModelToSchema(
        registry: $registry,
        jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
        typeResolver: TypeResolver::create(),
        typeNodeResolver: TypeNodeResolver::create(),
        docBlockParser: DocBlockParser::create(),
        logger: $logger,
        factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
    );

    $key = $reader->build($modelClass);

    /** @var OA\Schema $schema */
    $schema = collect($registry->all())->firstWhere('schema', $key);

    return $schema;
}

function readOnlyModelProperty(OA\Schema $schema, string $name): OA\Property
{
    /** @var OA\Property $property */
    $property = collect($schema->properties)->firstWhere('property', $name);

    return $property;
}

it('marks the primary key and timestamp columns readOnly', function (): void {
    $schema = buildReadOnlyModelSchema(Article::class);

    expect(readOnlyModelProperty($schema, 'id')->readOnly)->toBeTrue()
        ->and(readOnlyModelProperty($schema, 'created_at')->readOnly)->toBeTrue()
        ->and(readOnlyModelProperty($schema, 'updated_at')->readOnly)->toBeTrue();
});

it('marks the soft-delete column readOnly', function (): void {
    $schema = buildReadOnlyModelSchema(SoftDeletingArticle::class);

    expect(readOnlyModelProperty($schema, 'deleted_at')->readOnly)->toBeTrue();
});

it('marks a custom primary key readOnly, not a hard-coded id', function (): void {
    $schema = buildReadOnlyModelSchema(CustomPrimaryKeyArticle::class);

    expect(readOnlyModelProperty($schema, 'uuid')->readOnly)->toBeTrue();
});

it('does not mark timestamps when the model has none', function (): void {
    $schema = buildReadOnlyModelSchema(UntimestampedArticle::class);

    // No timestamp columns exist; only the primary key would be marked, and `name` stays writable.
    expect(collect($schema->properties)->pluck('property'))->not->toContain('created_at')
        ->and(is_undefined(readOnlyModelProperty($schema, 'name')->readOnly))->toBeTrue();
});

it('does not mark ordinary attributes readOnly', function (): void {
    $schema = buildReadOnlyModelSchema(Article::class);

    expect(is_undefined(readOnlyModelProperty($schema, 'title')->readOnly))->toBeTrue()
        ->and(is_undefined(readOnlyModelProperty($schema, 'status')->readOnly))->toBeTrue()
        ->and(is_undefined(readOnlyModelProperty($schema, 'reading_time')->readOnly))->toBeTrue();
});

it('does not crash on a non-instantiable model (null metadata)', function (): void {
    $schema = buildReadOnlyModelSchema(AbstractModel::class);

    // Null metadata returns a bare {type: object} before the readOnly pass: no properties, no crash.
    expect($schema->type)->toBe('object')
        ->and(is_undefined($schema->properties))->toBeTrue();
});

it('is idempotent: building the same model twice yields stable readOnly markings', function (): void {
    $first = buildReadOnlyModelSchema(Article::class);
    $second = buildReadOnlyModelSchema(Article::class);

    expect(readOnlyModelProperty($first, 'id')->readOnly)->toBe(readOnlyModelProperty($second, 'id')->readOnly)
        ->and(readOnlyModelProperty($second, 'id')->readOnly)->toBeTrue();
});
