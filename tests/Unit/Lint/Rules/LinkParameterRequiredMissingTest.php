<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\LinkParameterRequiredMissing;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param array<string, string> $parameters
 */
function makeLinkRequiredMissingNode(?string $operationId, array $parameters): LinkNode
{
    $link = OperationNodeFactory::makeLink(
        name: 'TestLink',
        operationId: $operationId,
        parameters: $parameters,
    );
    OperationNodeFactory::makeOperation(
        method: HttpMethod::Post,
        responses: [OperationNodeFactory::makeResponse(statusCode: 201, description: null, links: [$link])],
    );

    return $link;
}

/**
 * @param list<string>                              $pathParams
 * @param list<array{name: string, required: bool}> $queryParams
 */
function makeLinkRequiredMissingContext(
    string $targetOperationId,
    array $pathParams,
    array $queryParams,
): LintContext {
    $targetOp = OperationNodeFactory::makeOperation(
        pathUri: '/target',
        operationId: $targetOperationId,
        parameters: array_map(
            static fn(string $name): ParameterNode => OperationNodeFactory::makeParameter(name: $name, schema: null),
            $pathParams,
        ),
        queryParameters: array_map(
            static fn(array $data): QueryParameterNode
                => OperationNodeFactory::makeQueryParameter(
                    name: $data['name'],
                    required: $data['required'],
                    type: null,
                    hasSchema: false,
                ),
            $queryParams,
        ),
    );

    return OperationNodeFactory::emptyContext(
        operationsByOperationId: [$targetOperationId => $targetOp],
    );
}

it('reports its id and level', function (): void {
    $rule = new LinkParameterRequiredMissing();

    expect($rule->id())->toBe('link.parameter-required-missing')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all required parameters are supplied', function (): void {
    $link = makeLinkRequiredMissingNode(
        operationId: 'foo.show',
        parameters: ['id' => '$response.body#/id'],
    );
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('flags missing required path parameters', function (string $pathParam): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: [$pathParam],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('link.parameter-required-missing')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain($pathParam);
})->with([
    'id' => ['id'],
    'slug' => ['slug'],
]);

it('does not flag optional query parameters as missing', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: [],
        queryParams: [['name' => 'filter', 'required' => false]],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits a finding per missing required parameter', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [['name' => 'version', 'required' => true]],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('id')
        ->and($findings[1]->message)->toContain('version');
});

it('emits no finding when target operation has no parameters', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.index', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.index',
        pathParams: [],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('skips links that use operationRef instead of operationId (out of scope)', function (): void {
    // Links using operationRef are not validated by this rule; only operationId-based links are
    // checked. A null operationId with a non-null operationRef on the raw link is the real-world
    // scenario, but the rule guards solely on operationId being null.
    $link = makeLinkRequiredMissingNode(operationId: null, parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});
