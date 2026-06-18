<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponents\ComponentRefController;
use Stringable;

uses()->group('openapi');

// region Helpers

/** Captures logged messages so a test can assert the genuinely-absent ref is reported. */
function componentHarvestLogger(): LoggerInterface
{
    return new class () extends AbstractLogger {
        /** @var list<string> */
        public array $messages = [];

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
}

function componentHarvestSetup(?LoggerInterface $logger = null): void
{
    Route::get('/widgets', [ComponentRefController::class, 'index']);
    Route::get('/gadgets', [ComponentRefController::class, 'missing']);

    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    if ($logger !== null) {
        app()->instance(LoggerInterface::class, $logger);
    }

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhpComponents'],
            $app->make(LoggerInterface::class),
        ),
    );
}

function componentHarvestDocument(): OA\OpenApi
{
    return app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
}

/**
 * @return array<string, OA\Response>
 */
function responseComponents(OA\OpenApi $document): array
{
    $byName = [];
    $responses = $document->components instanceof OA\Components ? $document->components->responses : null;

    foreach (is_array($responses) ? $responses : [] as $response) {
        $byName[(string) $response->response] = $response;
    }

    return $byName;
}

/**
 * @return array<string, OA\Parameter>
 */
function parameterComponents(OA\OpenApi $document): array
{
    $byName = [];
    $parameters = $document->components instanceof OA\Components ? $document->components->parameters : null;

    foreach (is_array($parameters) ? $parameters : [] as $parameter) {
        $byName[(string) $parameter->parameter] = $parameter;
    }

    return $byName;
}

function operationFor(OA\OpenApi $document, string $needle): ?OA\Operation
{
    foreach (is_array($document->paths) ? $document->paths : [] as $pathItem) {
        if (str_contains((string) $pathItem->path, $needle) && $pathItem->get instanceof OA\Operation) {
            return $pathItem->get;
        }
    }

    return null;
}

// endregion

it('harvests an authored @OA\Response component under its authored name', function (): void {
    componentHarvestSetup();

    $responses = responseComponents(componentHarvestDocument());

    expect($responses)->toHaveKey('NotFound')
        ->and($responses['NotFound']->description)->toBe('The resource was not found.');
});

it('harvests an authored @OA\Parameter component under its authored name', function (): void {
    componentHarvestSetup();

    $parameters = parameterComponents(componentHarvestDocument());

    expect($parameters)->toHaveKey('PageParam')
        ->and((string) $parameters['PageParam']->name)->toBe('page');
});

it('pulls in a schema the harvested response references transitively', function (): void {
    componentHarvestSetup();

    $document = componentHarvestDocument();
    $schemas = $document->components instanceof OA\Components ? $document->components->schemas : null;
    $schemaNames = array_map(
        static fn(OA\Schema $s): string => (string) $s->schema,
        is_array($schemas) ? $schemas : [],
    );

    expect($schemaNames)->toContain('ErrorBody');
});

it('keeps an operation response/parameter $ref that resolves to a harvested component', function (): void {
    componentHarvestSetup();

    $operation = operationFor(componentHarvestDocument(), 'widgets');

    $responseRefs = array_map(
        static fn(OA\Response $r): ?string => is_string($r->ref) ? $r->ref : null,
        is_array($operation?->responses) ? $operation->responses : [],
    );
    $parameterRefs = array_map(
        static fn(OA\Parameter $p): ?string => is_string($p->ref) ? $p->ref : null,
        is_array($operation?->parameters) ? $operation->parameters : [],
    );

    expect($responseRefs)->toContain('#/components/responses/NotFound')
        ->and($parameterRefs)->toContain('#/components/parameters/PageParam');
});

it('drops and logs an operation response $ref whose target component is absent', function (): void {
    $logger = componentHarvestLogger();
    componentHarvestSetup($logger);

    $operation = operationFor(componentHarvestDocument(), 'gadgets');

    $refs = array_map(
        static fn(OA\Response $r): ?string => is_string($r->ref) ? $r->ref : null,
        is_array($operation?->responses) ? $operation->responses : [],
    );

    expect($refs)->not->toContain('#/components/responses/Missing')
        ->and($logger->messages)->toContain(
            'SwaggerPhp harvester: authored response $ref "#/components/responses/Missing" targets an unknown response component; skipping.',
        );
});

it('produces a document that serializes and validates as OpenAPI 3.1', function (): void {
    componentHarvestSetup();

    $document = componentHarvestDocument();

    // Runs in the non-snapshot path so the swagger-php 5.8 CI job exercises the component shapes.
    expect($document->toYaml())
        ->toContain('#/components/responses/NotFound')
        ->toContain('#/components/parameters/PageParam');

    $analysis = new Analysis([], new Context());
    $analysis->openapi = $document;

    expect($analysis->validate())->toBeTrue();
});
