<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Extractors\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Extractors\RequestBodyExtractor;
use Radiergummi\OpenApi\Core\Extractors\SchemaFromFormRequest;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\SpatieData\DataClassRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\DataSyntheticPayloadBuilder;
use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\ExampleFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\StandardResponsesFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use ReflectionClass;
use ReflectionMethod;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('openapi');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeRequestBodyExtractor(): array
{
    $registry = new ComponentSchemaRegistry();

    $scanner = new PayloadParameterScanner(indirectionClasses: [Action::class]);

    $dataResolver = new DataClassRequestSchemaResolver(
        schemaBuilder: new SchemaFromDataClass(
            schemaFromType: new JsonSchemaFromType(new NullLogger()),
            typeResolver: TypeResolver::create(),
            registry: $registry,
            payloadBuilder: new DataSyntheticPayloadBuilder(app(DataConfig::class)),
            rulesToSchema: new ValidationRulesToSchema(),
            dataConfig: app(DataConfig::class),
            logger: new NullLogger(),
        ),
        scanner: $scanner,
    );

    $formRequestResolver = new FormRequestRequestSchemaResolver(
        schemaBuilder: new SchemaFromFormRequest(
            rulesMapper: new ValidationRulesToSchema(),
            registry: $registry,
        ),
        registry: $registry,
        scanner: $scanner,
    );

    $findings  = new ArrayFindingsCollector();
    $extractor = new RequestBodyExtractor(
        resolvers: [$dataResolver, $formRequestResolver],
        findings: $findings,
    );

    return [$extractor, $findings];
}

function makeRequestBodyDescriptor(string $class, string $methodName, string $httpMethod = 'POST'): ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod($class, $methodName, 'test', [$httpMethod]);
}

// ---------------------------------------------------------------------------
// Data class resolver — JSON body with $ref
// ---------------------------------------------------------------------------

it('returns a RequestBody when the action type-hints a Data class', function (): void {
    [$extractor] = makeRequestBodyExtractor();
    $descriptor  = makeRequestBodyDescriptor(ExampleFixtureController::class, 'create');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeInstanceOf(OA\RequestBody::class)
        ->and($result->required)->toBeTrue();
});

it('sets application/json media type for a Data class without file properties', function (): void {
    [$extractor] = makeRequestBodyExtractor();
    $descriptor  = makeRequestBodyDescriptor(ExampleFixtureController::class, 'create');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeInstanceOf(OA\RequestBody::class)
        ->and($result->content)->toBeArray()->toHaveCount(1)
        ->and($result->content[0])->toBeInstanceOf(OA\MediaType::class)
        ->and($result->content[0]->mediaType)->toBe('application/json');
});

it('sets a $ref on the schema for a Data class', function (): void {
    [$extractor] = makeRequestBodyExtractor();
    $descriptor  = makeRequestBodyDescriptor(ExampleFixtureController::class, 'create');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeInstanceOf(OA\RequestBody::class);
    $schema = $result->content[0]->schema;
    expect($schema)->toBeInstanceOf(OA\Schema::class)
        ->and($schema->ref)->toStartWith('#/components/schemas/');
});

// ---------------------------------------------------------------------------
// FormRequest resolver — JSON body with $ref
// ---------------------------------------------------------------------------

it('returns a RequestBody when the action type-hints a FormRequest', function (): void {
    [$extractor] = makeRequestBodyExtractor();
    $descriptor  = makeRequestBodyDescriptor(FileUploadFixtureController::class, 'upload');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeInstanceOf(OA\RequestBody::class)
        ->and($result->required)->toBeTrue();
});

it('sets multipart/form-data for a FormRequest with file fields', function (): void {
    [$extractor] = makeRequestBodyExtractor();
    $descriptor  = makeRequestBodyDescriptor(FileUploadFixtureController::class, 'upload');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeInstanceOf(OA\RequestBody::class)
        ->and($result->content[0]->mediaType)->toBe('multipart/form-data');
});

it('uses application/json for a plain FormRequest without file fields', function (): void {
    $controller = new class () {
        public function store(SimpleFormRequest $request): void {}
    };

    [$extractor] = makeRequestBodyExtractor();
    $descriptor  = new ActionDescriptor(
        route: new Route('POST', 'test', []),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, 'store'),
        summary: null,
        description: null,
    );

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeInstanceOf(OA\RequestBody::class)
        ->and($result->content[0]->mediaType)->toBe('application/json');
});

// ---------------------------------------------------------------------------
// request.empty finding — POST with no body schema
// ---------------------------------------------------------------------------

it('emits a request.empty finding for POST with no recognised body schema', function (): void {
    [$extractor, $findings] = makeRequestBodyExtractor();
    $descriptor             = makeRequestBodyDescriptor(StandardResponsesFixtureController::class, 'throwsNothing', 'POST');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeNull()
        ->and($findings->all())->toHaveCount(1)
        ->and($findings->all()[0]->ruleId)->toBe('request.empty');
});

it('does not emit request.empty for GET requests with no body', function (): void {
    [$extractor, $findings] = makeRequestBodyExtractor();
    $descriptor             = makeRequestBodyDescriptor(StandardResponsesFixtureController::class, 'throwsNothing', 'GET');

    $result = $extractor->extractFromMethod($descriptor);

    expect($result)->toBeNull()
        ->and($findings->all())->toBeEmpty();
});

it('emits request.empty with the correct route URI and method', function (): void {
    [$extractor, $findings] = makeRequestBodyExtractor();

    $descriptor = ActionDescriptorFactory::forControllerMethod(
        StandardResponsesFixtureController::class,
        'throwsNothing',
        'projects/{project}',
        ['PATCH'],
    );

    $extractor->extractFromMethod($descriptor);

    $finding = $findings->all()[0];
    expect($finding->ruleId)->toBe('request.empty')
        ->and($finding->location->routeMethod)->toBe('PATCH')
        ->and($finding->location->routeUri)->toBe('projects/{project}')
        ->and($finding->fixHint)->toContain('Data class or FormRequest');
});
