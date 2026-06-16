<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ResponseRedirectWithoutLocation;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseRedirectWithoutLocation();

    expect($rule->id())
        ->toBe('response.redirect-without-location')
        ->and($rule->severity())->toBe(Severity::Underspecified);
});

it(
    'emits no finding when the response has a Location header (any case, mixed with other headers)',
    function (array $headers): void {
        $response = makeRedirectResponseNode('301', $headers);

        $findings = iterator_to_array(
            new ResponseRedirectWithoutLocation()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'exact case' => [['Location']],
    'lowercase' => [['location']],
    'alongside other headers' => [['X-Custom', 'Location']],
]);

it('emits no finding when a non-redirect response has no Location header', function (): void {
    $response = makeRedirectResponseNode('200', []);

    $findings = iterator_to_array(
        new ResponseRedirectWithoutLocation()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a 302 response has no Location header', function (): void {
    $response = makeRedirectResponseNode('302', []);

    $findings = iterator_to_array(
        new ResponseRedirectWithoutLocation()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.redirect-without-location')
        ->and($findings[0]->severity)->toBe(Severity::Underspecified)
        ->and($findings[0]->message)->toContain('302')
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/old')
        ->and($findings[0]->message)->toContain('no Location header');
});

/**
 * @param list<string> $headerNames
 */
function makeRedirectResponseNode(string $statusCode, array $headerNames): ResponseNode
{
    $response = OperationNodeFactory::makeResponse(
        statusCode: $statusCode,
        description: null,
        headers: array_map(
            static fn(string $name) => OperationNodeFactory::makeHeader(name: $name, schema: null),
            $headerNames,
        ),
    );

    OperationNodeFactory::makeOperation(pathUri: '/old', responses: [$response]);

    return $response;
}
