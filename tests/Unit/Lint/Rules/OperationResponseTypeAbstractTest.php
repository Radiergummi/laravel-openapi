<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\OperationResponseTypeAbstract;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

abstract class AbstractUnitResourceBase extends JsonResource {}

final class ConcreteUnitResource extends JsonResource {}

/**
 * Builds a 200 response whose envelope `data` field refs `$refName`, a linked operation, and a
 * context whose component index maps `$refName` to a node with the given source class / fields.
 *
 * @param null|class-string                             $sourceClass
 * @param list<Radiergummi\OpenApi\Lint\Tree\FieldNode> $componentFields
 *
 * @return array{0: Radiergummi\OpenApi\Lint\Tree\ResponseNode, 1: Radiergummi\OpenApi\Lint\LintContext}
 */
function abstractRuleFixture(
    string $refName,
    ?string $sourceClass,
    array $componentFields = [],
    ?OA\Schema $componentRaw = null,
    int $statusCode = 200,
): array {
    $dataField = OperationNodeFactory::makeField(name: 'data', type: 'object', ref: $refName);
    $response = OperationNodeFactory::makeResponse(statusCode: $statusCode, fields: [$dataField]);
    OperationNodeFactory::makeOperation(pathUri: '/things', method: HttpMethod::Get, responses: [$response]);

    $component = OperationNodeFactory::makeComponentSchema(
        name: $refName,
        fields: $componentFields,
        raw: $componentRaw,
        sourceClass: $sourceClass,
    );

    $context = OperationNodeFactory::emptyContext(componentsByName: [$refName => $component]);

    return [$response, $context];
}

it('reports its id and level', function (): void {
    $rule = new OperationResponseTypeAbstract();

    expect($rule->id)->toBe('operation.response-type-abstract')
        ->and($rule->severity)->toBe(Severity::Underspecified);
});

it('fires when the envelope refs an empty framework resource base', function (): void {
    $bases = [JsonResource::class, ResourceCollection::class, AnonymousResourceCollection::class];

    foreach ($bases as $base) {
        [$response, $context] = abstractRuleFixture('JsonResource', $base);

        $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('operation.response-type-abstract')
            ->and($findings[0]->message)->toContain('200')
            ->and($findings[0]->message)->toContain('GET /things')
            ->and($findings[0]->message)->toContain('narrow');
    }
});

it('fires for an app-defined abstract resource base', function (): void {
    [$response, $context] = abstractRuleFixture('AbstractUnitResourceBase', AbstractUnitResourceBase::class);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('AbstractUnitResourceBase');
});

it('names the offending base and fix in the message', function (): void {
    [$response, $context] = abstractRuleFixture('JsonResource', JsonResource::class);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings[0]->message)->toContain('JsonResource')
        ->and($findings[0]->fixHint)->toContain('->toResource()');
});

it('stays silent for a concrete resource subclass', function (): void {
    [$response, $context] = abstractRuleFixture('ConcreteUnitResource', ConcreteUnitResource::class);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toBe([]);
});

it('stays silent when the abstract-base component carries fields', function (): void {
    $field = OperationNodeFactory::makeField(name: 'id', type: 'integer');
    [$response, $context] = abstractRuleFixture('JsonResource', JsonResource::class, componentFields: [$field]);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toBe([]);
});

it('stays silent when the component schema carries array items', function (): void {
    $raw = new OA\Schema(['_context' => new Context(), 'items' => new OA\Items(['_context' => new Context()])]);
    [$response, $context] = abstractRuleFixture('JsonResource', JsonResource::class, componentRaw: $raw);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toBe([]);
});

it('stays silent when the component schema carries a composition branch', function (): void {
    $raw = new OA\Schema([
        '_context' => new Context(),
        'oneOf' => [
            new OA\Schema(['_context' => new Context(), 'type' => 'object']),
            new OA\Schema(['_context' => new Context(), 'type' => 'string']),
        ],
    ]);
    [$response, $context] = abstractRuleFixture('JsonResource', JsonResource::class, componentRaw: $raw);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toBe([]);
});

it('stays silent for a non-2xx response', function (): void {
    [$response, $context] = abstractRuleFixture('JsonResource', JsonResource::class, statusCode: 404);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toBe([]);
});

it('stays silent when the ref resolves to no component (broken ref)', function (): void {
    $dataField = OperationNodeFactory::makeField(name: 'data', type: 'object', ref: 'Missing');
    $response = OperationNodeFactory::makeResponse(statusCode: 200, fields: [$dataField]);
    OperationNodeFactory::makeOperation(pathUri: '/x', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new OperationResponseTypeAbstract()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('stays silent when the response has no schema ref at all', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, fields: []);
    OperationNodeFactory::makeOperation(pathUri: '/x', method: HttpMethod::Get, responses: [$response]);

    $findings = iterator_to_array(
        new OperationResponseTypeAbstract()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('fires via a top-level schemaRef (unwrapped resource response)', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, schemaRef: 'JsonResource');
    OperationNodeFactory::makeOperation(pathUri: '/y', method: HttpMethod::Get, responses: [$response]);

    $component = OperationNodeFactory::makeComponentSchema(name: 'JsonResource', sourceClass: JsonResource::class);
    $context = OperationNodeFactory::emptyContext(componentsByName: ['JsonResource' => $component]);

    $findings = iterator_to_array(new OperationResponseTypeAbstract()->checkResponse($response, $context));

    expect($findings)->toHaveCount(1);
});
