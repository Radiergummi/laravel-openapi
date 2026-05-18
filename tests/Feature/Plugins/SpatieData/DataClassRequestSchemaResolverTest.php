<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Routing\Route;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\SpatieData\DataClassRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\ExampleFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\PropertyFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\StandardResponsesFixtureController;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi', 'plugin:spatie-data');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeDataClassResolver(): DataClassRequestSchemaResolver
{
    $registry = new ComponentSchemaRegistry();
    $builder  = new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger()),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
    );

    return new DataClassRequestSchemaResolver(
        schemaBuilder: $builder,
        scanner: new PayloadParameterScanner(indirectionClasses: [Action::class]),
    );
}

/**
 * @param class-string $class
 *
 * @throws ReflectionException
 */
function makeDataClassDescriptor(string $class, string $methodName): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route('POST', 'test', []),
        controller: new ReflectionClass($class),
        method: new ReflectionMethod($class, $methodName),
        summary: null,
        description: null,
    );
}

// ---------------------------------------------------------------------------
// Happy path — Data class direct type-hint
// ---------------------------------------------------------------------------

it('returns a ResolvedSchema when the action type-hints a Data class', function (): void {
    $resolver   = makeDataClassResolver();
    $descriptor = makeDataClassDescriptor(ExampleFixtureController::class, 'create');

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeInstanceOf(ResolvedSchema::class)
        ->and($result->componentKey)->not->toBeEmpty();
});

it('uses application/json media type for a Data class without file properties', function (): void {
    $resolver   = makeDataClassResolver();
    $descriptor = makeDataClassDescriptor(ExampleFixtureController::class, 'create');

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeInstanceOf(ResolvedSchema::class)
        ->and($result->mediaType)->toBe(MediaType::Json);
});

it('registers the Data class schema in the component registry', function (): void {
    $registry = new ComponentSchemaRegistry();
    $builder  = new SchemaFromDataClass(
        schemaFromType: new JsonSchemaFromType(new NullLogger()),
        typeResolver: TypeResolver::create(),
        registry: $registry,
        payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
        rulesToSchema: new ValidationRulesToSchema(),
        dataConfig: app(DataConfig::class),
        logger: new NullLogger(),
    );
    $resolver = new DataClassRequestSchemaResolver(
        schemaBuilder: $builder,
        scanner: new PayloadParameterScanner(indirectionClasses: [Action::class]),
    );
    $descriptor = makeDataClassDescriptor(ExampleFixtureController::class, 'create');

    $resolver->resolveRequestSchema($descriptor);

    expect($registry->isRegisteredOrReserved(PropertyFixtureData::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Null cases
// ---------------------------------------------------------------------------

it('returns null when the action has no Data class parameter', function (): void {
    $resolver   = makeDataClassResolver();
    $descriptor = makeDataClassDescriptor(StandardResponsesFixtureController::class, 'throwsNothing');

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeNull();
});

it('returns null when the ActionDescriptor has no method', function (): void {
    $resolver   = makeDataClassResolver();
    $descriptor = new ActionDescriptor(
        route: new Route('GET', 'test', []),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// OAPI-010: Action constructor descent (indirection via scanner)
// ---------------------------------------------------------------------------

it('extracts a Data class from an Action constructor when the scanner is configured with an indirection class', function (): void {
    // ActionFixture extends the package-local Action fixture and carries ActionFixtureData in its constructor.
    // The resolver must find ActionFixtureData via the scanner's indirection descent.
    $controller = new class () {
        public function store(\Radiergummi\OpenApi\Tests\Fixtures\ActionFixture $action): void {}
    };

    $resolver   = makeDataClassResolver();
    $descriptor = new ActionDescriptor(
        route: new Route('POST', 'test', []),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, 'store'),
        summary: null,
        description: null,
    );

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeInstanceOf(ResolvedSchema::class)
        ->and($result->componentKey)->toContain('ActionFixtureData');
});
