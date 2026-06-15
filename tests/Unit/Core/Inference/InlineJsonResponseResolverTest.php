<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineJsonResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineJsonCallReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonWithAttributeController;

uses()->group('openapi');

// region Helpers

function inlineJsonResolver(?LoggerInterface $logger = null): InlineJsonResponseResolver
{
    return new InlineJsonResponseResolver(
        new MethodBodyScanner(),
        new InlineJsonCallReader(),
        $logger ?? new NullLogger(),
    );
}

/**
 * @param class-string $controller
 */
function inlineJsonActionDescriptor(
    string $method,
    string $controller = InlineJsonFixtureController::class,
): ActionDescriptor {
    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

/**
 * Serialises the annotation the way the generator would, so assertions read the same array
 * shape the feature tests see in the emitted document.
 *
 * @return array<string, mixed>
 */
function inlineJsonSchema(OA\Response $response): array
{
    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized['content'])->toHaveKey('application/json');

    return $serialized['content']['application/json']['schema'];
}

// endregion

// region Literal bodies

it('builds an object schema with typed properties from a literal array', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('literalObject'));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('200')
        ->and($response->description)->toBe('OK');

    $schema = inlineJsonSchema($response);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['message', 'success', 'attempts', 'score'])
        ->and($schema['properties']['message']['type'])->toBe('string')
        ->and($schema['properties']['success']['type'])->toBe('boolean')
        ->and($schema['properties']['attempts']['type'])->toBe('integer')
        ->and($schema['properties']['score']['type'])->toBe('number');
});

it('recurses into nested literal arrays', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('nestedLiteral'));

    $schema = inlineJsonSchema($response);
    $nested = $schema['properties']['data'];

    expect($nested['type'])->toBe('object')
        ->and($nested['properties']['id']['type'])->toBe('integer')
        ->and($nested['properties']['tags']['type'])->toBe('array')
        ->and($nested['properties']['tags']['items']['type'])->toBe('string');
});

it('builds an array schema with typed items from a literal list', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('listOfScalars'));

    $schema = inlineJsonSchema($response);

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('integer');
});

it('builds an array schema with object items from a literal list of objects', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('listOfObjects'));

    $schema = inlineJsonSchema($response);

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('object')
        ->and($schema['items']['properties'])->toHaveKeys(['id', 'name']);
});

it('keeps a dynamic value under a literal key as an unconstrained property', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('partialLiteral'));

    $schema = inlineJsonSchema($response);

    expect($schema['properties'])->toHaveKeys(['logs', 'success'])
        // The dynamic value keeps its property, as an unconstrained (empty) schema.
        ->and($schema['properties']['logs'])->toBe([])
        ->and($schema['properties']['success']['type'])->toBe('boolean');
});

it('matches a json call wrapped in a further method chain', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('chainedCall'));

    expect(inlineJsonSchema($response)['properties'])->toHaveKey('cached');
});

it('refuses a json call chained into a body-mutating method and logs a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('setDataChain'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('setData');
});

it('reads a literal ->setStatusCode() over the json chain and keeps the body', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('setStatusCodeLiteral'));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('201')
        ->and($response->description)->toBe('Created')
        ->and(inlineJsonSchema($response)['properties'])->toHaveKey('created');
});

it('resolves a class-constant ->setStatusCode() over the json chain', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('setStatusCodeConstant'));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('201')
        ->and(inlineJsonSchema($response)['properties'])->toHaveKey('created');
});

it('refuses a non-literal ->setStatusCode() and logs a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('setStatusCodeDynamic'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('setStatusCode');
});

it('refuses a literal non-2xx ->setStatusCode() and logs a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('setStatusCodeNonSuccess'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('non-2xx')
        ->and($logger->records[0]['message'])->toContain('403');
});

it('documents a 204 from ->noContent() without a body schema', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('noContent'));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('204')
        ->and($response->description)->toBe('No Content')
        ->and($logger->records)->toBeEmpty();

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('content');
});

it('reads a literal status argument on ->noContent() and stays body-less', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('noContentExplicitStatus'),
    );

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('200')
        ->and($response->description)->toBe('OK')
        ->and($logger->records)->toBeEmpty();

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('content');
});

it('reads a named status argument on ->noContent()', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('noContentNamedStatus'),
    );

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('202')
        ->and($response->description)->toBe('Accepted')
        ->and($logger->records)->toBeEmpty();
});

it('degrades a non-literal status argument on ->noContent() with a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('noContentDynamicStatus'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('response()->noContent()');
});

it('degrades a non-2xx literal status on ->noContent() with a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('noContentNonSuccess'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('404')
        ->and($logger->records[0]['message'])->toContain('response()->noContent()');
});

it('documents explicit sequential integer keys as a JSON array (AST path)', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('integerKeyedList'));

    $schema = inlineJsonSchema($response);

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string')
        ->and($schema)->not->toHaveKey('properties');
});

it('documents explicit sequential integer keys as a JSON array (evaluated class-constant path)', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(
        inlineJsonActionDescriptor('integerKeyedListConstant'),
    );

    $schema = inlineJsonSchema($response);

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['type'])->toBe('string')
        ->and($schema)->not->toHaveKey('properties');
});

it('prefers a returned json call over an earlier one only assigned to a variable', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('assignedThenReturned'));

    expect($response->response)->toBe('201')
        ->and(inlineJsonSchema($response)['properties'])->toHaveKey('second');
});

// endregion

// region Status arguments

it('uses a literal status argument as the response status', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('literalStatus'));

    expect($response->response)->toBe('201')
        ->and($response->description)->toBe('Created');
});

it('resolves a class-constant status argument', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('classConstantStatus'));

    expect($response->response)->toBe('202')
        ->and($response->description)->toBe('Accepted');
});

it('reads named data and status arguments regardless of order', function (): void {
    $response = inlineJsonResolver()->resolvePrimaryResponse(inlineJsonActionDescriptor('namedArguments'));

    expect($response->response)->toBe('201');

    $schema = inlineJsonSchema($response);

    expect($schema['properties'])->toHaveKey('queued')
        ->and($schema['properties']['queued']['type'])->toBe('boolean');
});

it('refuses the call and logs a note when the status argument is not literal', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('dynamicStatus'));

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('status');
});

it('refuses a straight-line non-2xx literal so the success response is not evicted', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('guardedSuccessWithTerminalError'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('non-2xx')
        ->and($logger->records[0]['message'])->toContain('403');
});

it('refuses a nonsense literal status via the 2xx guard', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('nonsenseStatus'));

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('999');
});

it('documents a 204 without a body schema', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('noContentStatus'));

    expect($response)->not->toBeNull()
        ->and($response->response)->toBe('204')
        ->and($response->description)->toBe('No Content')
        ->and($logger->records)->toBeEmpty();

    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toHaveKey('content');
});

// endregion

// region Refused shapes

it('refuses a variable body and logs a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('variableBody'));

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('#[Response]');
});

it('refuses a model expression body and logs a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('modelBody'));

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('refuses the whole array when a key is dynamic', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('dynamicKey'));

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

it('refuses a json call that only occurs in a conditional context and logs a note', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('conditionalOnly'));

    expect($response)->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('conditional');
});

// endregion

// region Silent skips

it('skips silently when json() has no data argument', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('emptyJson'));

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

it('skips silently for an empty array literal', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('emptyArrayBody'));

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

it('skips silently when no json call is present', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('noJsonCall'));

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

it('skips silently when the json call sits beyond the statement limit', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(inlineJsonActionDescriptor('beyondStatementLimit'));

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

it('never scans an action whose return type already carries schema information', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('typedReturnWithJsonBody'),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

it('steps aside silently when the action carries a #[ResponseResource] authoring attribute', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('resourceAuthored', InlineJsonWithAttributeController::class),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

it('steps aside silently when the action carries a #[FractalResponse] authoring attribute', function (): void {
    $logger = recordingLogger();

    $response = inlineJsonResolver($logger)->resolvePrimaryResponse(
        inlineJsonActionDescriptor('fractalAuthored', InlineJsonWithAttributeController::class),
    );

    expect($response)->toBeNull()
        ->and($logger->records)->toBeEmpty();
});

// endregion
