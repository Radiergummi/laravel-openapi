<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Extensions\OperationContext;
use Radiergummi\OpenApi\Core\Extensions\SchemaContext;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
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
        static function (OA\Operation $op, OperationContext $ctx) use (&$seen): void {
            $seen[] = $ctx->httpMethod . ' ' . $ctx->routeUri;
        },
    );

    app(OpenApiGenerator::class)->generate();

    expect($seen)->toContain('POST oa-ext/post')
        ->and($seen)->toContain('GET oa-ext/get');
});

it('allows an operation transformer to mutate the operation', function (): void {
    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $ctx): void {
            if ($ctx->routeUri === 'oa-ext/get') {
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
        static function (OA\Operation $op, OperationContext $ctx) use (&$methods): void {
            $methods[] = $ctx->httpMethod;
        },
    );

    app(OpenApiGenerator::class)->generate();

    expect($methods)->toContain('POST')
        ->and($methods)->toContain('GET');
});

it('passes the correct controller class and method name in the operation context', function (): void {
    $seen = [];

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $ctx) use (&$seen): void {
            if ($ctx->routeUri === 'oa-ext/post') {
                $seen['controller'] = $ctx->controllerClass;
                $seen['method'] = $ctx->methodName;
            }
        },
    );

    app(OpenApiGenerator::class)->generate();

    expect($seen['controller'])->toBe(ExtensionsFixtureController::class)
        ->and($seen['method'])->toBe('postAction');
});

// endregion

// region Schema transformer

it('invokes a registered schema transformer for each component schema', function (): void {
    $seenKeys = [];

    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $ctx) use (&$seenKeys): void {
            $seenKeys[] = $ctx->componentKey;
        },
    );

    app(OpenApiGenerator::class)->generate();

    // ExtensionsFixtureData is the only Data class referenced, so its key must appear.
    expect($seenKeys)->toContain('ExtensionsFixtureData');
});

it('passes the source class in the schema context', function (): void {
    $seenClass = null;

    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $ctx) use (&$seenClass): void {
            if ($ctx->componentKey === 'ExtensionsFixtureData') {
                $seenClass = $ctx->sourceClass;
            }
        },
    );

    app(OpenApiGenerator::class)->generate();

    expect($seenClass)->toBe(ExtensionsFixtureData::class);
});

it('allows a schema transformer to mutate the schema', function (): void {
    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $ctx): void {
            if ($ctx->componentKey === 'ExtensionsFixtureData') {
                $schema->description = 'Injected by transformer';
            }
        },
    );

    $spec = generateSpec();

    expect($spec['components']['schemas']['ExtensionsFixtureData']['description'])
        ->toBe('Injected by transformer');
});

it('passes null sourceClass for named schemas', function (): void {
    $namedCtx = null;

    OpenApiExtensions::transformSchema(
        static function (OA\Schema $schema, SchemaContext $ctx) use (&$namedCtx): void {
            if ($ctx->sourceClass === null) {
                $namedCtx = $ctx;
            }
        },
    );

    // Named schemas — shared envelopes registered via registerNamed() (e.g. by plugins) —
    // carry no originating Data/Resource class. The SchemaContext must reflect that.
    (new ComponentSchemaRegistry())->registerNamed(
        'SharedEnvelope',
        new OA\Schema(['type' => 'object']),
    );

    expect($namedCtx)->not->toBeNull()
        ->and($namedCtx->componentKey)->toBe('SharedEnvelope')
        ->and($namedCtx->sourceClass)->toBeNull();
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

    app(OpenApiGenerator::class)->generate();

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
        static function (OA\Operation $op, OperationContext $ctx) use (&$log): void {
            if ($ctx->routeUri === 'oa-ext/get') {
                $log[] = 'first';
            }
        },
    );

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $ctx) use (&$log): void {
            if ($ctx->routeUri === 'oa-ext/get') {
                $log[] = 'second';
            }
        },
    );

    app(OpenApiGenerator::class)->generate();

    expect($log)->toBe(['first', 'second']);
});

// endregion

// region flush() isolates registrations between test runs

it('flush() removes all registered transformers', function (): void {
    $called = false;

    OpenApiExtensions::transformOperation(
        static function (OA\Operation $op, OperationContext $ctx) use (&$called): void {
            $called = true;
        },
    );

    OpenApiExtensions::flush();

    app(OpenApiGenerator::class)->generate();

    expect($called)->toBeFalse();
});

// endregion
