<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Extractors\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Extractors\SchemaFromFormRequest;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\Examples\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Core\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Core\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\FileUploadFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\SimpleFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\StandardResponsesFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// region Helpers

function makeFormRequestResolver(): FormRequestRequestSchemaResolver
{
    $registry = new ComponentSchemaRegistry();
    $builder  = new SchemaFromFormRequest(
        rulesMapper: new ValidationRulesToSchema(),
        registry: $registry,
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: false),
        findings: new ArrayFindingsCollector(),
    );

    return new FormRequestRequestSchemaResolver(
        schemaBuilder: $builder,
        registry: $registry,
        scanner: new PayloadParameterScanner(indirectionClasses: [Action::class]),
    );
}

function makeFormRequestDescriptor(string $class, string $methodName): ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod($class, $methodName, 'test', ['POST']);
}

// endregion

// region Happy path — FormRequest present

it('returns a ResolvedSchema when the action type-hints a FormRequest', function (): void {
    $resolver   = makeFormRequestResolver();
    $descriptor = makeFormRequestDescriptor(FileUploadFixtureController::class, 'upload');

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeInstanceOf(ResolvedSchema::class)
        ->and($result->componentKey)->not->toBeEmpty();
});

it('uses application/json media type for a plain FormRequest', function (): void {
    // Build a descriptor pointing at a controller method that type-hints SimpleFormRequest.
    // We use an inline controller fixture so we don't need an extra file.
    $controller = new class () {
        public function store(SimpleFormRequest $request): void {}
    };

    $resolver   = makeFormRequestResolver();
    $descriptor = ActionDescriptorFactory::forRoute(
        route: new Route('POST', 'test', []),
        controller: $controller::class,
        method: 'store',
    );

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeInstanceOf(ResolvedSchema::class)
        ->and($result->mediaType)->toBe(MediaType::Json);
});

it('uses multipart/form-data media type when FormRequest has file fields', function (): void {
    $resolver   = makeFormRequestResolver();
    $descriptor = makeFormRequestDescriptor(FileUploadFixtureController::class, 'upload');

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeInstanceOf(ResolvedSchema::class)
        ->and($result->mediaType)->toBe(MediaType::MultipartFormData);
});

it('registers the FormRequest schema in the component registry', function (): void {
    $registry = new ComponentSchemaRegistry();
    $builder  = new SchemaFromFormRequest(
        rulesMapper: new ValidationRulesToSchema(),
        registry: $registry,
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: false),
        findings: new ArrayFindingsCollector(),
    );
    $resolver = new FormRequestRequestSchemaResolver(
        schemaBuilder: $builder,
        registry: $registry,
        scanner: new PayloadParameterScanner(indirectionClasses: [Action::class]),
    );

    $descriptor = makeFormRequestDescriptor(FileUploadFixtureController::class, 'upload');
    $resolver->resolveRequestSchema($descriptor);

    expect($registry->isRegisteredOrReserved(FileUploadFormRequest::class))->toBeTrue();
});

// endregion

// region Null cases — no FormRequest on the action

it('returns null when the action has no FormRequest parameter', function (): void {
    $resolver   = makeFormRequestResolver();
    $descriptor = makeFormRequestDescriptor(StandardResponsesFixtureController::class, 'throwsNothing');

    $result = $resolver->resolveRequestSchema($descriptor);

    expect($result)->toBeNull();
});

it('returns null when the ActionDescriptor has no method', function (): void {
    $resolver   = makeFormRequestResolver();
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

// endregion
