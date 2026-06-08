<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DanglingController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\InvoiceController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// region Helpers

function harvestStage(ComponentSchemaRegistry $schemaRegistry, ?LoggerInterface $logger = null): HarvestAuthoredAnnotationsStage
{
    $logger ??= recordingLogger();
    $scanner = new AuthoredAnnotationScanner(
        [dirname(__DIR__, 3) . '/Fixtures/SwaggerPhp'],
        $logger,
    );

    return new HarvestAuthoredAnnotationsStage($scanner, $schemaRegistry, $logger);
}

function harvestSpec(): SpecDefinition
{
    return new SpecDefinition(
        name: 'default',
        info: new OA\Info(['title' => 'Test', 'version' => '0.0.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: 'openapi.yaml',
        routeUri: null,
        playgroundUri: null,
    );
}

function harvestDoc(OA\Operation $operation): OA\OpenApi
{
    $pathItem = new OA\PathItem(['path' => '/x']);
    $pathItem->{$operation->method} = $operation;
    $doc = new OA\OpenApi([]);
    $doc->paths = [$pathItem];

    return $doc;
}

/**
 * @return list<string> The component schema names the harvester registered into the shared pool.
 */
function harvestSchemaNames(ComponentSchemaRegistry $schemaRegistry): array
{
    return array_map(static fn(OA\Schema $s): string => $s->schema, $schemaRegistry->all());
}

/**
 * Pulls the `$ref` out of the JSON response body for a given status (default `200`), or null.
 */
function harvestPrimaryRef(OA\Operation $operation, string $status = '200'): ?string
{
    foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
        if ((string) $response->response !== $status || !is_array($response->content)) {
            continue;
        }

        foreach ($response->content as $mediaType) {
            $ref = $mediaType->schema instanceof OA\Schema ? $mediaType->schema->ref : null;

            if (is_string($ref) && str_starts_with($ref, '#/')) {
                return $ref;
            }
        }
    }

    return null;
}

// endregion

// region Case 1: Typed return resolving to an authored #[OA\Schema] model

it('attaches a $ref response when the return type carries an authored schema', function (): void {
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');
    $ctx->bindAction($operation, ActionDescriptorFactory::forControllerMethod(ServerController::class, 'show'));

    $schemaRegistry = new ComponentSchemaRegistry();
    harvestStage($schemaRegistry)->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBe('#/components/schemas/Server')
        ->and(harvestSchemaNames($schemaRegistry))->toContain('Server');
});

// endregion

// region Case 2: Authored operation response merged + transitive schema registration

it('merges an authored operation response and registers its schemas transitively', function (): void {
    $operation = new OA\Get([
        'responses' => [
            new OA\Response(['response' => '200', 'description' => 'OK']),
            new OA\Response(['response' => '500', 'description' => 'Server error']),
        ],
    ]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');
    $ctx->bindAction($operation, ActionDescriptorFactory::forControllerMethod(InvoiceController::class, 'show'));

    $schemaRegistry = new ComponentSchemaRegistry();
    harvestStage($schemaRegistry)->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBe('#/components/schemas/Invoice')
        ->and(harvestSchemaNames($schemaRegistry))->toContain('Invoice')->toContain('InvoiceLine');

    // Pre-existing inferred responses for other statuses survive the merge.
    $statuses = array_map(static fn(OA\Response $r): string => (string) $r->response, $operation->responses);
    expect($statuses)->toContain('500');

    // The authored operation is the source of truth for its own prose/metadata.
    expect($operation->summary)->toBe('Show an invoice.')
        ->and($operation->operationId)->toBe('showInvoice');
});

// endregion

// region Case 3: Dangling ref — merge skipped, logged, no component added

it('skips an authored response with an unresolvable ref and logs it', function (): void {
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');
    $ctx->bindAction($operation, ActionDescriptorFactory::forControllerMethod(DanglingController::class, 'index'));

    $logger = recordingLogger();
    $schemaRegistry = new ComponentSchemaRegistry();
    harvestStage($schemaRegistry, $logger)->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBeNull()
        ->and(harvestSchemaNames($schemaRegistry))->not->toContain('DoesNotExist');

    $messages = array_map(static fn(array $r): string => $r['message'], $logger->records);
    expect(implode("\n", $messages))->toContain('DoesNotExist');
});

// endregion

// region Case 4: No bound action leaves the operation untouched

it('leaves an operation untouched when it has no bound action', function (): void {
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');

    $schemaRegistry = new ComponentSchemaRegistry();
    harvestStage($schemaRegistry)->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBeNull()
        ->and(harvestSchemaNames($schemaRegistry))->toBe([]);
});

// endregion

// region Case 5: A content-ful 200 is not clobbered by the return-type path

it('does not overwrite a 200 that already has a response body', function (): void {
    $existing = new OA\Response([
        'response' => '200',
        'description' => 'OK',
        'content' => [new OA\MediaType(['mediaType' => 'application/json', 'schema' => new OA\Schema(['type' => 'string'])])],
    ]);
    $operation = new OA\Get(['responses' => [$existing]]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');
    $ctx->bindAction($operation, ActionDescriptorFactory::forControllerMethod(ServerController::class, 'show'));

    $schemaRegistry = new ComponentSchemaRegistry();
    harvestStage($schemaRegistry)->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBeNull()
        ->and(harvestSchemaNames($schemaRegistry))->toBe([]);
});

// endregion

// region Case 6: An authored operation that declares no summary keeps the inferred one

it('keeps the inferred summary when the authored operation declares none', function (): void {
    // DanglingController's @OA\Get sets no summary/operationId.
    $operation = new OA\Get([
        'summary' => 'Inferred summary.',
        'responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])],
    ]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');
    $ctx->bindAction($operation, ActionDescriptorFactory::forControllerMethod(DanglingController::class, 'index'));

    harvestStage(new ComponentSchemaRegistry())->apply($doc, $ctx);

    expect($operation->summary)->toBe('Inferred summary.');
});

// endregion

// region Case 7: The return-type schema fills an existing non-200 success, no second success code

it('fills an existing non-200 success response instead of adding a second 200', function (): void {
    // A `store`-style operation whose convention success is 201 (body-less).
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '201', 'description' => 'Created'])]]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');
    $ctx->bindAction($operation, ActionDescriptorFactory::forControllerMethod(ServerController::class, 'show'));

    harvestStage(new ComponentSchemaRegistry())->apply($doc, $ctx);

    $statuses = array_map(static fn(OA\Response $r): string => (string) $r->response, $operation->responses);

    expect($statuses)->toBe(['201'])
        ->and(harvestPrimaryRef($operation, '201'))->toBe('#/components/schemas/Server');
});

// endregion
