<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Generator\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use Radiergummi\OpenApi\Plugins\SpatieData\DataRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\DataResponseResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function assert;

uses()->group('openapi', 'plugin:spatie-data');

class DataResolverFixtureController
{
    public function single(string $id): ScalarOnlyData
    { /** @phpstan-ignore-next-line */ return null;
    }

    /**
     * @return DataCollection<int, ScalarOnlyData>
     */
    public function collection(): DataCollection
    { /** @phpstan-ignore-next-line */ return null;
    }

    /**
     * @return PaginatedDataCollection<int, ScalarOnlyData>
     */
    public function paginated(): PaginatedDataCollection
    { /** @phpstan-ignore-next-line */ return null;
    }

    /**
     * @return CursorPaginatedDataCollection<int, ScalarOnlyData>
     */
    public function cursorPaginated(): CursorPaginatedDataCollection
    { /** @phpstan-ignore-next-line */ return null;
    }

    public function untypedCollection(): DataCollection
    { /** @phpstan-ignore-next-line */ return null;
    }

    public function notData(): string
    {
        return '';
    }
}

beforeEach(function (): void {
    $registry  = new ComponentSchemaRegistry();
    $dataConfig = app(DataConfig::class);

    $schemaFromDataClass = new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger()),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder($dataConfig),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: $dataConfig,
        logger: new NullLogger(),
    );

    $this->resolver = new DataResponseResolver(
        refResolver: new DataRefSchemaResolver(
            schemaFromDataClass: $schemaFromDataClass,
            schemaRegistry: $registry,
        ),
        returnTypeExtractor: ReturnTypeExtractor::create(),
        schemaFactory: new PaginatorSchemaFactory(),
        logger: new NullLogger(),
    );
});

function dataResolverDescriptor(string $method): \Radiergummi\OpenApi\Core\Routing\ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod(DataResolverFixtureController::class, $method);
}

function firstMediaSchema(OA\Response $response): OA\Schema
{
    $content = $response->content;
    assert(is_array($content));
    $media = $content[0];
    assert($media instanceof OA\MediaType);
    $schema = $media->schema;
    assert($schema instanceof OA\Schema);

    return $schema;
}

it('emits a 200 with a $ref for a single Data return type', function (): void {
    $response = $this->resolver->resolvePrimaryResponse(dataResolverDescriptor('single'));

    expect($response)->not->toBeNull()
        ->and((string) $response->response)->toBe('200')
        ->and($response->description)->toBe('OK');

    $schema = firstMediaSchema($response);
    expect($schema->ref)->toBe('#/components/schemas/ScalarOnlyData');
});

it('emits an array schema for a DataCollection<X> return type', function (): void {
    $response = $this->resolver->resolvePrimaryResponse(dataResolverDescriptor('collection'));

    expect($response)->not->toBeNull();

    $schema = firstMediaSchema($response);
    expect($schema->type)->toBe('array')
        ->and($schema->items)->toBeInstanceOf(OA\Items::class)
        ->and($schema->items->ref)->toBe('#/components/schemas/ScalarOnlyData');
});

it('emits a length-aware paginator envelope for PaginatedDataCollection<X>', function (): void {
    $response = $this->resolver->resolvePrimaryResponse(dataResolverDescriptor('paginated'));

    expect($response)->not->toBeNull();

    $schema = firstMediaSchema($response);
    expect($schema->type)->toBe('object');

    // Length-aware envelope carries data, per_page, path, current_page, last_page, total, links, …
    $propertyNames = [];

    foreach ($schema->properties as $prop) {
        $propertyNames[] = $prop->property;
    }

    expect($propertyNames)->toContain('data')
        ->and($propertyNames)->toContain('per_page')
        ->and($propertyNames)->toContain('current_page')
        ->and($propertyNames)->toContain('last_page')
        ->and($propertyNames)->toContain('total');
});

it('emits a cursor paginator envelope for CursorPaginatedDataCollection<X>', function (): void {
    $response = $this->resolver->resolvePrimaryResponse(dataResolverDescriptor('cursorPaginated'));

    expect($response)->not->toBeNull();

    $schema = firstMediaSchema($response);

    $propertyNames = [];

    foreach ($schema->properties as $prop) {
        $propertyNames[] = $prop->property;
    }

    expect($propertyNames)->toContain('data')
        ->and($propertyNames)->toContain('next_cursor')
        ->and($propertyNames)->toContain('prev_cursor')
        ->and($propertyNames)->not->toContain('current_page');
});

it('returns null when the collection has no generic item type', function (): void {
    $response = $this->resolver->resolvePrimaryResponse(dataResolverDescriptor('untypedCollection'));

    expect($response)->toBeNull();
});

it('returns null when the return type is not a Data class or container', function (): void {
    $response = $this->resolver->resolvePrimaryResponse(dataResolverDescriptor('notData'));

    expect($response)->toBeNull();
});
