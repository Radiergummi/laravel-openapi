<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\OperationContext;
use Radiergummi\OpenApi\Extensions\SchemaContext;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Spatie\LaravelData\Data;

uses()->group('openapi');

// region A minimal Data class whose schema we can track through transformers.
class ExtensionsFixtureData extends Data
{
    public function __construct(
        public string $label,
    ) {}
}

// endregion

// region Minimal fixture controller
class ExtensionsFixtureController
{
    public function postAction(ExtensionsFixtureData $input): array
    {
        return [];
    }

    public function getAction(): array
    {
        return [];
    }
}

beforeEach(function (): void {
    OpenApiExtensions::flush();

    Route::post('/oa-ext/post', [ExtensionsFixtureController::class, 'postAction']);
    Route::get('/oa-ext/get', [ExtensionsFixtureController::class, 'getAction']);
});

afterEach(function (): void {
    OpenApiExtensions::flush();
});

// endregion

// region Operation transformer

it('invokes a registered operation transformer for each assembled operation', function (): void {
    $seen = [];

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context) use (&$seen): void {
            $seen[] = $context->httpMethod->forDisplay() . ' ' . $context->routeUri;
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($seen)->toContain('POST oa-ext/post')
        ->and($seen)->toContain('GET oa-ext/get');
});

it('allows an operation transformer to mutate the operation', function (): void {
    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context): void {
            if ($context->routeUri === 'oa-ext/get') {
                $op->summary = 'Overridden by transformer';
            }
        },
    );

    $spec = generateSpec();

    expect($spec['paths']['/oa-ext/get']['get']['summary'])
        ->toBe('Overridden by transformer');
});

it('passes the correct HTTP method in the operation context', function (): void {
    $methods = [];

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context) use (&$methods): void {
            $methods[] = $context->httpMethod;
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($methods)->toContain(HttpMethod::Post)
        ->and($methods)->toContain(HttpMethod::Get);
});

it('passes the correct controller class and method name in the operation context', function (): void {
    $seen = [];

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context) use (&$seen): void {
            if ($context->routeUri === 'oa-ext/post') {
                $seen['controller'] = $context->controllerClass;
                $seen['method'] = $context->methodName;
            }
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($seen['controller'])->toBe(ExtensionsFixtureController::class)
        ->and($seen['method'])->toBe('postAction');
});

// endregion

// region Schema transformer

it('invokes a registered schema transformer for each component schema', function (): void {
    $seenKeys = [];

    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $context) use (&$seenKeys): void {
            $seenKeys[] = $context->componentKey;
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    // ExtensionsFixtureData is the only Data class referenced, so its key must appear.
    expect($seenKeys)->toContain('ExtensionsFixtureData');
});

it('passes the source class in the schema context', function (): void {
    $seenClass = null;

    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $context) use (&$seenClass): void {
            if ($context->componentKey === 'ExtensionsFixtureData') {
                $seenClass = $context->sourceClass;
            }
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($seenClass)->toBe(ExtensionsFixtureData::class);
});

it('allows a schema transformer to mutate the schema', function (): void {
    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $context): void {
            if ($context->componentKey === 'ExtensionsFixtureData') {
                $schema->description = 'Injected by transformer';
            }
        },
    );

    $spec = generateSpec();

    expect($spec['components']['schemas']['ExtensionsFixtureData']['description'])
        ->toBe('Injected by transformer');
});

// endregion

// region Document transformer

it('invokes a registered document transformer exactly once', function (): void {
    $callCount = 0;

    OpenApiExtensions::transformDocument(
        static function (OA\OpenApi $doc) use (&$callCount): void {
            $callCount++;
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($callCount)->toBe(1);
});

it('allows a document transformer to mutate the document', function (): void {
    OpenApiExtensions::transformDocument(
        static function (OA\OpenApi $doc): void {
            $doc->info->title = 'Title set by transformer';
        },
    );

    $spec = generateSpec();

    expect($spec['info']['title'])->toBe('Title set by transformer');
});

// endregion

// region Multiple transformers — all are called, in registration order

it('runs multiple operation transformers in registration order', function (): void {
    $log = [];

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context) use (&$log): void {
            if ($context->routeUri === 'oa-ext/get') {
                $log[] = 'first';
            }
        },
    );

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context) use (&$log): void {
            if ($context->routeUri === 'oa-ext/get') {
                $log[] = 'second';
            }
        },
    );

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($log)->toBe(['first', 'second']);
});

// endregion

// region flush() isolates registrations between test runs

it('flush() removes all registered transformers', function (): void {
    $called = false;

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $context) use (&$called): void {
            $called = true;
        },
    );

    OpenApiExtensions::flush();

    app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');

    expect($called)->toBeFalse();
});

// endregion
