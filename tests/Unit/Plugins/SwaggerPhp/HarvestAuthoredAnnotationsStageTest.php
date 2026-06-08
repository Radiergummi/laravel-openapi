<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DanglingController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\InvoiceController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

// region Helpers

function harvestStage(?LoggerInterface $logger = null): HarvestAuthoredAnnotationsStage
{
    $logger ??= recordingLogger();
    $scanner = new AuthoredAnnotationScanner(
        [dirname(__DIR__, 3) . '/Fixtures/SwaggerPhp'],
        $logger,
    );

    return new HarvestAuthoredAnnotationsStage($scanner, $logger);
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
 * @return list<string> The component schema names registered on the document.
 */
function harvestSchemaNames(OA\OpenApi $doc): array
{
    if (!$doc->components instanceof OA\Components || !is_array($doc->components->schemas)) {
        return [];
    }

    return array_map(static fn(OA\Schema $s): string => $s->schema, $doc->components->schemas);
}

/**
 * Pulls the `$ref` out of an operation's primary (200) JSON response body, or null.
 */
function harvestPrimaryRef(OA\Operation $operation): ?string
{
    foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
        if ((string) $response->response !== '200' || !is_array($response->content)) {
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

    harvestStage()->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBe('#/components/schemas/Server')
        ->and(harvestSchemaNames($doc))->toContain('Server');
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

    harvestStage()->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBe('#/components/schemas/Invoice')
        ->and(harvestSchemaNames($doc))->toContain('Invoice')->toContain('InvoiceLine');

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
    harvestStage($logger)->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBeNull()
        ->and(harvestSchemaNames($doc))->not->toContain('DoesNotExist');

    $messages = array_map(static fn(array $r): string => $r['message'], $logger->records);
    expect(implode("\n", $messages))->toContain('DoesNotExist');
});

// endregion

// region Case 4: No bound action leaves the operation untouched

it('leaves an operation untouched when it has no bound action', function (): void {
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);
    $doc = harvestDoc($operation);
    $ctx = new GenerationContext(harvestSpec(), 'testing');

    harvestStage()->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBeNull()
        ->and(harvestSchemaNames($doc))->toBe([]);
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

    harvestStage()->apply($doc, $ctx);

    expect(harvestPrimaryRef($operation))->toBeNull()
        ->and(harvestSchemaNames($doc))->toBe([]);
});

// endregion
