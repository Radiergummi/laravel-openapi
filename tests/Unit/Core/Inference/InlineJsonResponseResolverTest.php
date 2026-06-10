<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineJsonResponseResolver;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonFixtureController;

uses()->group('openapi');

// region Helpers

function inlineJsonResolver(?LoggerInterface $logger = null): InlineJsonResponseResolver
{
    return new InlineJsonResponseResolver(new MethodBodyScanner(), $logger ?? new NullLogger());
}

function inlineJsonActionDescriptor(string $method): ActionDescriptor
{
    /** @var class-string $controller */
    $controller = InlineJsonFixtureController::class;

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

// endregion
