<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\ResponseSuccessEmptyBody;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * A raw 200 response carrying the given media types, mirroring what swagger-php holds on the
 * response node. Passing no media types produces a response without any `content` at all.
 */
function rawSuccessResponse(OA\MediaType ...$mediaTypes): OA\Response
{
    $properties = ['response' => 200, 'description' => 'OK', '_context' => new Context()];

    if ($mediaTypes !== []) {
        $properties['content'] = $mediaTypes;
    }

    return new OA\Response($properties);
}

function rawMediaType(string $mediaType, ?OA\Schema $schema = null): OA\MediaType
{
    $properties = ['mediaType' => $mediaType, '_context' => new Context()];

    if ($schema !== null) {
        $properties['schema'] = $schema;
    }

    return new OA\MediaType($properties);
}

/**
 * @param array<string, mixed> $properties
 */
function rawSchema(array $properties = []): OA\Schema
{
    return new OA\Schema([...$properties, '_context' => new Context()]);
}

it('reports its id and level', function (): void {
    $rule = new ResponseSuccessEmptyBody();

    expect($rule->id)->toBe('response.success-empty-body')
        ->and($rule->severity)->toBe(Severity::Underspecified);
});

it('emits a finding for a 200 response with no body schema', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, fields: [], schemaRef: null);
    OperationNodeFactory::makeOperation(pathUri: '/users', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.success-empty-body')
        ->and($findings[0]->message)->toContain('200')
        ->and($findings[0]->message)->toContain('GET /users');
});

it('does not flag a 200 response that has an inline schema', function (): void {
    $field = OperationNodeFactory::makeField(name: 'id', type: 'integer');
    $response = OperationNodeFactory::makeResponse(statusCode: 200, fields: [$field]);
    OperationNodeFactory::makeOperation(pathUri: '/users/1', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 response that references a component schema', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, schemaRef: 'User');
    OperationNodeFactory::makeOperation(pathUri: '/users/1', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 whose body is a scalar schema', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType('application/json', rawSchema(['type' => 'string']))),
    );
    OperationNodeFactory::makeOperation(pathUri: '/ping', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 whose body is a binary octet-stream download', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType(
            'application/octet-stream',
            rawSchema(['type' => 'string', 'format' => 'binary']),
        )),
    );
    OperationNodeFactory::makeOperation(pathUri: '/exports/1', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 whose body is a nullable scalar widened to a type array', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType(
            'application/json',
            rawSchema(['type' => ['string', 'null']]),
        )),
    );
    OperationNodeFactory::makeOperation(pathUri: '/ping', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 whose body is a nullable ref split into a oneOf', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType('application/json', rawSchema([
            'oneOf' => [
                rawSchema(['ref' => '#/components/schemas/User']),
                rawSchema(['type' => 'null']),
            ],
        ]))),
    );
    OperationNodeFactory::makeOperation(pathUri: '/users/1', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 whose body is a composition without a type', function (string $keyword): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType('application/json', rawSchema([
            $keyword => [rawSchema(['type' => 'string'])],
        ]))),
    );
    OperationNodeFactory::makeOperation(pathUri: '/unions', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'oneOf' => 'oneOf',
    'anyOf' => 'anyOf',
    'allOf' => 'allOf',
]);

it('does not flag a 200 when only one of several media types declares a schema', function (): void {
    // One media type declaring a body is enough: the question the rule asks is whether the
    // response advertises *a* body, not whether every media type does. Schema-less first so the
    // case would fail were the quantifier ever narrowed to "every".
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(
            rawMediaType('application/json'),
            rawMediaType('application/octet-stream', rawSchema(['type' => 'string', 'format' => 'binary'])),
        ),
    );
    OperationNodeFactory::makeOperation(pathUri: '/exports/1', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('still flags a 200 whose media type declares no schema', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType('application/json')),
    );
    OperationNodeFactory::makeOperation(pathUri: '/users', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1);
});

it('still flags a 200 whose media-type schema declares nothing', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        raw: rawSuccessResponse(rawMediaType('application/json', rawSchema())),
    );
    OperationNodeFactory::makeOperation(pathUri: '/users', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1);
});

it('still flags a 200 that declares no content at all', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, raw: rawSuccessResponse());
    OperationNodeFactory::makeOperation(pathUri: '/users', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1);
});

it('keeps skipping a bodiless code even when a scalar body is declared', function (): void {
    $response = OperationNodeFactory::makeResponse(
        statusCode: 204,
        raw: rawSuccessResponse(rawMediaType('application/json', rawSchema(['type' => 'string']))),
    );
    OperationNodeFactory::makeOperation(pathUri: '/things/1', method: HttpMethod::Delete, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('skips bodiless success codes', function (int $statusCode): void {
    $response = OperationNodeFactory::makeResponse(statusCode: $statusCode);
    OperationNodeFactory::makeOperation(pathUri: '/things', method: HttpMethod::Delete, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    '204' => 204,
    '205' => 205,
    '304' => 304,
]);

it('skips non-2xx responses', function (int $statusCode): void {
    $response = OperationNodeFactory::makeResponse(statusCode: $statusCode);
    OperationNodeFactory::makeOperation(pathUri: '/x', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    '404' => 404,
    '500' => 500,
]);

it('skips HEAD responses (HEAD bodies are intentionally suppressed)', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200);
    OperationNodeFactory::makeOperation(pathUri: '/users', method: HttpMethod::Head, responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
