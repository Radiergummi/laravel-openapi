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
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Lint\TreeIndex;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

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
     * tests opt in to the Data-plugin payload-class detection path;
     * `$declaredTags` lets api-level rules (e.g. `tag.*`) see a non-empty
     * `ApiNode->declaredTags`.
     *
     * @param list<class-string>           $payloadClasses
     * @param list<string>                 $declaredTags
     * @param array<string, OperationNode> $operationsByOperationId prepopulates the `TreeIndex` lookup
     *                                                              used by link / security cross-ref rules
     * @param list<OperationNode>          $operations              populates `ApiNode->operations`; used by
     *                                                              api-level rules (e.g. `tag.undeclared-at-root`)
     * @param list<WebhookNode>            $webhooks                populates `ApiNode->webhooks`
     * @param array<string, string>        $tagDescriptions         tag name → description; used by `tags.no-description`
     * @param list<string>                 $registeredScopes        prepopulates `TreeIndex->registeredScopes`;
     *                                                              used by security/scope rules
     */
    public static function emptyContext(
        array $payloadClasses = [],
        array $declaredTags = [],
        array $operationsByOperationId = [],
        array $operations = [],
        array $webhooks = [],
        array $tagDescriptions = [],
        array $registeredScopes = [],
    ): LintContext {
        $spec = new OA\OpenApi(['openapi' => '3.1.0']);
        $index = $operationsByOperationId === [] && $registeredScopes === []
            ? TreeIndex::empty()
            : new TreeIndex(
                operationsByOperationId: $operationsByOperationId,
                operationsByRouteKey: [],
                componentsByName: [],
                referencedComponents: [],
                registeredScopes: $registeredScopes,
                knownRuleIds: [],
            );

        return new LintContext(
            api: new ApiNode(
                operations: $operations,
                components: [],
                webhooks: $webhooks,
                declaredTags: $declaredTags,
                tagDescriptions: $tagDescriptions,
                raw: $spec,
            ),
            index: $index,
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
     * @param list<ParameterNode>                               $parameters
     * @param list<QueryParameterNode>                          $queryParameters
     * @param null|list<ResponseNode>                           $responses       defaults to a single 200 response
     * @param list<array{scheme: string, scopes: list<string>}> $security
     * @param list<string>                                      $tags
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
     * @param list<FieldNode>   $fields
     * @param list<ExampleNode> $examples
     * @param list<HeaderNode>  $headers
     * @param list<LinkNode>    $links
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
        $response = new ResponseNode(
            statusCode: $statusCode,
            description: $description,
            fields: $fields,
            examples: $examples,
            schemaRef: $schemaRef,
            headers: $headers,
            links: $links,
            raw: $raw,
        );

        foreach ($headers as $header) {
            $header->linkParent($response);
        }

        foreach ($links as $link) {
            $link->linkParent($response);
        }

        return $response;
    }

    /**
     * A `HeaderNode` with safe defaults. Wrap inside `makeResponse(headers:
     * [...])` to attach the parent automatically.
     */
    public static function makeHeader(
        string $name = 'X-Header',
        ?string $schema = 'string',
        ?string $description = null,
        bool $required = false,
        ?OA\Header $raw = null,
    ): HeaderNode {
        return new HeaderNode(
            name: $name,
            schema: $schema,
            description: $description,
            required: $required,
            raw: $raw,
        );
    }

    /**
     * An `ExampleNode` with safe defaults.
     */
    public static function makeExample(
        ?string $name = 'default',
        mixed $value = ['id' => '123'],
        ?string $summary = null,
        ?string $description = null,
        ?OA\Examples $raw = null,
    ): ExampleNode {
        return new ExampleNode(
            name: $name,
            value: $value,
            summary: $summary,
            description: $description,
            raw: $raw,
        );
    }

    /**
     * A `LinkNode` with safe defaults. Wrap inside `makeResponse(links: [...])`
     * to attach the parent automatically.
     *
     * @param array<string, string> $parameters
     */
    public static function makeLink(
        string $name = 'GetFoo',
        ?string $operationId = null,
        ?string $operationRef = null,
        array $parameters = [],
        ?string $description = null,
        ?OA\Link $raw = null,
    ): LinkNode {
        return new LinkNode(
            name: $name,
            operationId: $operationId,
            operationRef: $operationRef,
            parameters: $parameters,
            description: $description,
            raw: $raw,
        );
    }

    /**
     * A `RequestBodyNode` with safe defaults.
     *
     * @param list<string>      $contentTypes
     * @param list<FieldNode>   $fields
     * @param list<ExampleNode> $examples
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
     * A path/header-style `ParameterNode` with safe defaults. Wrap inside
     * `makeOperation(parameters: [...])` to attach the parent automatically.
     *
     * @param list<ExampleNode> $examples
     */
    public static function makeParameter(
        string $name = 'id',
        bool $required = true,
        ?string $schema = 'string',
        ?string $description = null,
        ?string $pattern = null,
        array $examples = [],
        ?OA\Parameter $raw = null,
    ): ParameterNode {
        return new ParameterNode(
            name: $name,
            required: $required,
            schema: $schema,
            description: $description,
            pattern: $pattern,
            examples: $examples,
            raw: $raw,
        );
    }

    /**
     * A `QueryParameterNode` with safe defaults. Wrap inside
     * `makeOperation(queryParameters: [...])` to attach the parent automatically.
     *
     * @param null|list<string> $enum
     * @param list<ExampleNode> $examples
     */
    public static function makeQueryParameter(
        string $name = 'q',
        bool $required = false,
        ?string $type = 'string',
        ?bool $hasSchema = null,
        ?string $style = null,
        ?bool $explode = null,
        ?string $description = null,
        ?array $enum = null,
        array $examples = [],
        ?OA\Parameter $raw = null,
    ): QueryParameterNode {
        return new QueryParameterNode(
            name: $name,
            required: $required,
            type: $type,
            hasSchema: $hasSchema ?? $type !== null,
            style: $style,
            explode: $explode,
            description: $description,
            enum: $enum,
            examples: $examples,
            raw: $raw,
        );
    }

    /**
     * A `FieldNode` with safe defaults. Standalone — caller is responsible for
     * placing it inside a parent's `fields:` list if a rule walks up the tree.
     *
     * @param null|list<mixed>  $enum
     * @param list<FieldNode>   $children
     * @param list<ExampleNode> $examples
     */
    public static function makeField(
        string $name = 'field',
        ?string $type = 'string',
        bool $required = false,
        bool $nullable = false,
        ?string $description = null,
        ?string $format = null,
        mixed $example = null,
        ?array $enum = null,
        array $children = [],
        array $examples = [],
        ?string $ref = null,
        ?OA\Property $raw = null,
    ): FieldNode {
        return new FieldNode(
            name: $name,
            type: $type,
            required: $required,
            nullable: $nullable,
            description: $description,
            format: $format,
            example: $example,
            enum: $enum,
            children: $children,
            examples: $examples,
            ref: $ref,
            raw: $raw,
        );
    }

    /**
     * A `WebhookNode` with safe defaults. The wrapped operation is built via
     * `makeOperation()` unless callers pass their own. `linkParent` is left to
     * the caller — the api-level `WebhookNameDuplicate` finalize path doesn't
     * need it, and most other rules only inspect the wrapped operation.
     */
    public static function makeWebhook(
        string $name = 'sample.event',
        ?string $description = null,
        ?OperationNode $operation = null,
        mixed $raw = null,
    ): WebhookNode {
        return new WebhookNode(
            name: $name,
            description: $description,
            operation: $operation ?? self::makeOperation(
                pathUri: $name,
                method: 'POST',
                operationId: 'webhook.' . $name,
                responses: [],
                webhook: true,
            ),
            raw: $raw,
        );
    }

    /**
     * A `ComponentSchemaNode` with safe defaults.
     *
     * @param list<FieldNode> $fields
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
