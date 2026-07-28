<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\SchemaFromDataClass;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Tests\Fixtures\RedundantNullableAttributeData;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi');

function makeNullableDataSchemaBuilder(ComponentSchemaRegistry $registry): SchemaFromDataClass
{
    return new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger(), $registry),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: false),
        explicitSchema: new ExplicitClassSchema(new NullLogger()),
    );
}

it('wraps a nullable Data property once even when an attribute repeats the nullability', function (): void {
    $registry = new ComponentSchemaRegistry();
    makeNullableDataSchemaBuilder($registry)->build(RedundantNullableAttributeData::class);

    $parent = collect($registry->all())
        ->first(fn(OA\Schema $schema): bool => $schema->schema === 'RedundantNullableAttributeData');
    $properties = is_array($parent->properties) ? $parent->properties : [];

    $child = collect($properties)
        ->first(fn(OA\Property $property): bool => $property->property === 'child');

    // A second wrap nests the split, and null then matches both outer branches, so the schema
    // accepts nothing at all.
    expect($child?->oneOf)
        ->toHaveCount(2)
        ->and($child?->oneOf[0]->ref)->toBe('#/components/schemas/ScalarOnlyData')
        ->and($child?->oneOf[1]->type)->toBe('null');
});
