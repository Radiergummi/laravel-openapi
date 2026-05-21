<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Support;

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * Builds minimal lint-tree fixtures for lint-rule tests.
 *
 * Three building blocks:
 *   - `emptyContext()` — a `LintContext` that lint rules can dereference.
 *   - `makeOperation()` / `makeResponse()` / `makeRequestBody()` / `makeComponentSchema()` —
 *     standalone nodes with sensible defaults; `makeOperation()` links its children.
 *   - `forDescriptor()` — an `OperationNode` carrying a real `ActionDescriptor`,
 *     for rules that inspect reflection (e.g. `OperationSecurityMissing`).
 */
final class OperationNodeFactory
{
    /**
     * An `OperationNode` carrying `$descriptor`. Document-shaped fields are empty
     * by default — most lint rules resolve everything from the descriptor's
     * reflection rather than from the produced operation. Optional overrides let
     * tests vary the HTTP method, the document-side `pathUri` / `operationId`,
     * or the `raw` swagger-php operation when a rule actually inspects them.
     */
    public static function forDescriptor(
        ActionDescriptor $descriptor,
        string $method = 'GET',
        ?string $pathUri = null,
        ?string $operationId = null,
        ?OA\Operation $raw = null,
    ): OperationNode {
        return new OperationNode(
            pathUri: $pathUri ?? $descriptor->route->uri(),
            method: $method,
            operationId: $operationId,
            summary: null,
            description: null,
            deprecated: false,
            parameters: [],
            queryParameters: [],
            requestBody: null,
            responses: [],
            security: [],
            tags: [],
            descriptor: $descriptor,
            raw: $raw ?? self::rawForMethod($method),
            webhook: false,
        );
    }

    /**
     * A minimal valid `LintContext`. The plugin lint rules never read it, but
     * `checkOperation()` requires a non-null instance. `$payloadClasses` lets
     * tests opt in to the Data-plugin payload-class detection path.
     *
     * @param list<class-string> $payloadClasses
     */
    public static function emptyContext(array $payloadClasses = []): LintContext
    {
        $spec = new OA\OpenApi(['openapi' => '3.1.0']);

        return new LintContext(
            api: new ApiNode(
                operations: [],
                components: [],
                webhooks: [],
                declaredTags: [],
                tagDescriptions: [],
                raw: $spec,
            ),
            index: TreeIndex::empty(),
            rawSpec: $spec,
            actionDescriptors: [],
            suppressions: [],
            payloadClasses: $payloadClasses,
        );
    }

    /**
     * A standalone `OperationNode` with safe defaults. Children passed in
     * `$responses`, `$requestBody`, `$parameters`, and `$queryParameters` are
     * linked to the produced operation so that rules walking up the tree
     * (e.g. for `pathUri` / `method`) work without ceremony.
     *
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode>      $parameters
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode> $queryParameters
     * @param null|list<ResponseNode>                                      $responses       defaults to a single 200 response
     * @param list<array{scheme: string, scopes: list<string>}>            $security
     * @param list<string>                                                 $tags
     */
    public static function makeOperation(
        string $pathUri = '/test',
        string $method = 'GET',
        ?string $operationId = 'test.index',
        ?string $summary = null,
        ?string $description = null,
        bool $deprecated = false,
        array $parameters = [],
        array $queryParameters = [],
        ?RequestBodyNode $requestBody = null,
        ?array $responses = null,
        array $security = [],
        array $tags = [],
        ?ActionDescriptor $descriptor = null,
        ?OA\Operation $raw = null,
        bool $webhook = false,
    ): OperationNode {
        $responses ??= [self::makeResponse()];

        $operation = new OperationNode(
            pathUri: $pathUri,
            method: $method,
            operationId: $operationId,
            summary: $summary,
            description: $description,
            deprecated: $deprecated,
            parameters: $parameters,
            queryParameters: $queryParameters,
            requestBody: $requestBody,
            responses: $responses,
            security: $security,
            tags: $tags,
            descriptor: $descriptor,
            raw: $raw ?? self::rawForMethod($method),
            webhook: $webhook,
        );

        foreach ($responses as $response) {
            $response->linkParent($operation);
        }

        foreach ($parameters as $parameter) {
            $parameter->linkParent($operation);
        }

        foreach ($queryParameters as $queryParameter) {
            $queryParameter->linkParent($operation);
        }

        $requestBody?->linkParent($operation);

        return $operation;
    }

    /**
     * A `ResponseNode` with safe defaults. Use within `makeOperation(responses:
     * [...])` to attach parents automatically.
     *
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\FieldNode>   $fields
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\ExampleNode> $examples
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode>  $headers
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\LinkNode>    $links
     */
    public static function makeResponse(
        int|string $statusCode = 200,
        ?string $description = 'OK',
        array $fields = [],
        array $examples = [],
        ?string $schemaRef = null,
        array $headers = [],
        array $links = [],
        ?OA\Response $raw = null,
    ): ResponseNode {
        return new ResponseNode(
            statusCode: $statusCode,
            description: $description,
            fields: $fields,
            examples: $examples,
            schemaRef: $schemaRef,
            headers: $headers,
            links: $links,
            raw: $raw,
        );
    }

    /**
     * A `RequestBodyNode` with safe defaults.
     *
     * @param list<string>                                          $contentTypes
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\FieldNode>   $fields
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\ExampleNode> $examples
     */
    public static function makeRequestBody(
        array $contentTypes = ['application/json'],
        bool $required = true,
        array $fields = [],
        array $examples = [],
        ?string $schemaRef = null,
        ?string $description = null,
        ?OA\RequestBody $raw = null,
    ): RequestBodyNode {
        return new RequestBodyNode(
            contentTypes: $contentTypes,
            required: $required,
            fields: $fields,
            examples: $examples,
            schemaRef: $schemaRef,
            description: $description,
            raw: $raw,
        );
    }

    /**
     * A `ComponentSchemaNode` with safe defaults.
     *
     * @param list<\Radiergummi\OpenApi\Core\Lint\Tree\FieldNode> $fields
     */
    public static function makeComponentSchema(
        string $name = 'Fixture',
        ?string $description = null,
        array $fields = [],
        ?OA\Schema $raw = null,
    ): ComponentSchemaNode {
        return new ComponentSchemaNode(
            name: $name,
            description: $description,
            fields: $fields,
            raw: $raw,
        );
    }

    private static function rawForMethod(string $method): OA\Operation
    {
        $context = ['_context' => new Context()];

        return match (strtoupper($method)) {
            'POST' => new OA\Post($context),
            'PUT' => new OA\Put($context),
            'DELETE' => new OA\Delete($context),
            'PATCH' => new OA\Patch($context),
            'HEAD' => new OA\Head($context),
            'OPTIONS' => new OA\Options($context),
            default => new OA\Get($context),
        };
    }
}
