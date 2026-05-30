<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function strtoupper;

/**
 * Transforms the raw `OA\OpenApi` annotation graph into a clean, typed domain tree.
 *
 * This is the single place that handles `Generator::UNDEFINED` sentinels, normalizes
 * annotation quirks, and correlates operations with ActionDescriptors.
 */
final class SpecTreeBuilder
{
    /** @var list<string> */
    private const HTTP_METHODS = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'options',
        'head',
        'trace',
    ];

    /**
     * Component schemas indexed by name — populated at the start of {@see build()} so
     * {@see buildFields()} can resolve `allOf: [{$ref: …}]` branches to the underlying
     * properties.
     *
     * @var array<string, OA\Schema>
     */
    private array $componentSchemaIndex = [];

    /**
     * @param array<string, string> $componentClassMap Component key → originating PHP class,
     *                                                 as returned by
     *                                                 {@see ComponentSchemaRegistry::componentClassMap()}.
     *                                                 Used to populate
     *                                                 {@see ComponentSchemaNode::$sourceClass}.
     */
    public function __construct(
        private readonly array $componentClassMap = [],
    ) {}

    /**
     * Build the domain tree from an OpenAPI spec and action descriptors.
     *
     * @param list<ActionDescriptor> $actionDescriptors
     *
     * @throws LogicException
     */
    public function build(OA\OpenApi $spec, array $actionDescriptors): ApiNode
    {
        $this->componentSchemaIndex = $this->indexComponentSchemas($spec);

        $descriptorIndex = $this->buildDescriptorIndex($actionDescriptors);
        $operations = $this->buildOperations($spec, $descriptorIndex);
        $components = $this->buildComponents($spec);
        $webhooks = $this->buildWebhooks($spec);
        [$tags, $tagDescriptions] = $this->buildTags($spec);

        $api = new ApiNode(
            operations: $operations,
            components: $components,
            webhooks: $webhooks,
            declaredTags: $tags,
            tagDescriptions: $tagDescriptions,
            raw: $spec,
        );

        // Link parent references (Phase 2 of two-phase construction)
        foreach ($operations as $op) {
            $op->linkParent($api);
        }

        foreach ($components as $comp) {
            $comp->linkParent($api);
        }

        foreach ($webhooks as $wh) {
            $wh->linkParent($api);
        }

        return $api;
    }

    /**
     * @return array<string, OA\Schema>
     */
    private function indexComponentSchemas(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if ($components === Generator::UNDEFINED || $components === null) {
            return [];
        }

        $schemas = $components->schemas;

        if ($schemas === Generator::UNDEFINED || !is_array($schemas)) {
            return [];
        }

        $index = [];

        foreach ($schemas as $schema) {
            if (
                !$schema instanceof OA\Schema
                || $schema === Generator::UNDEFINED // @phpstan-ignore identical.alwaysFalse (defensive; swagger-php may leave the sentinel in place at runtime)
            ) {
                continue;
            }

            if ($schema->schema === Generator::UNDEFINED) {
                continue;
            }

            $index[$schema->schema] = $schema;
        }

        return $index;
    }

    /**
     * Build an index of action descriptors keyed by "METHOD /uri".
     *
     * @param list<ActionDescriptor> $descriptors
     *
     * @return array<string, ActionDescriptor>
     */
    private function buildDescriptorIndex(array $descriptors): array
    {
        $index = [];

        foreach ($descriptors as $descriptor) {
            foreach ($descriptor->route->methods() as $method) {
                $key
                    = strtoupper($method)
                    . ' /'
                    . ltrim($descriptor->route->uri(), '/');
                $index[$key] = $descriptor;
            }
        }

        return $index;
    }

    /**
     * @param array<string, ActionDescriptor> $descriptorIndex
     *
     * @return list<OperationNode>
     *
     * @throws LogicException
     */
    private function buildOperations(
        OA\OpenApi $spec,
        array $descriptorIndex,
    ): array {
        $operations = [];
        $paths = $spec->paths;

        if (!is_array($paths)) {
            return $operations;
        }

        foreach ($paths as $path) {
            if ($path === Generator::UNDEFINED) {
                continue;
            }

            $pathUri
                = $path->path !== Generator::UNDEFINED
                ? $path->path
                : '(unknown)';

            foreach (self::HTTP_METHODS as $method) {
                $oaOperation = $path->{$method} ?? null;

                if (
                    $oaOperation === Generator::UNDEFINED
                    || $oaOperation === null
                ) {
                    continue;
                }

                $upperMethod = strtoupper($method);
                $descriptorKey = $upperMethod . ' ' . $pathUri;
                $descriptor = $descriptorIndex[$descriptorKey] ?? null;

                $operations[] = $this->buildOperation(
                    $oaOperation,
                    $pathUri,
                    $upperMethod,
                    $descriptor,
                    webhook: false,
                );
            }
        }

        return $operations;
    }

    /**
     * @throws LogicException
     */
    private function buildOperation(
        OA\Operation $oaOperation,
        string $pathUri,
        string $method,
        ?ActionDescriptor $descriptor,
        bool $webhook,
    ): OperationNode {
        $parameters = $this->buildParameters($oaOperation);
        $queryParameters = $this->buildQueryParameters($oaOperation);
        $requestBody = $this->buildRequestBody($oaOperation);
        $responses = $this->buildResponses($oaOperation);
        $security = $this->buildSecurity($oaOperation);
        $tags = $this->buildOperationTags($oaOperation);

        $operation = new OperationNode(
            pathUri: $pathUri,
            method: $method,
            operationId: SchemaAccessor::undefinedToNull($oaOperation->operationId),
            summary: SchemaAccessor::undefinedToNull($oaOperation->summary),
            description: SchemaAccessor::undefinedToNull($oaOperation->description),
            deprecated: $oaOperation->deprecated !== Generator::UNDEFINED
            && $oaOperation->deprecated === true,
            parameters: $parameters,
            queryParameters: $queryParameters,
            requestBody: $requestBody,
            responses: $responses,
            security: $security,
            tags: $tags,
            descriptor: $descriptor,
            raw: $oaOperation,
            webhook: $webhook,
        );

        // Link children's parent references
        foreach ($parameters as $parameter) {
            $parameter->linkParent($operation);
        }

        foreach ($queryParameters as $parameter) {
            $parameter->linkParent($operation);
        }

        $requestBody?->linkParent($operation);

        foreach ($responses as $response) {
            $response->linkParent($operation);
        }

        return $operation;
    }

    /**
     * Extract path parameters from the operation.
     *
     * @return list<ParameterNode>
     *
     * @throws LogicException
     */
    private function buildParameters(OA\Operation $operation): array
    {
        $params = $operation->parameters;

        if ($params === Generator::UNDEFINED || !is_array($params)) {
            return [];
        }

        $result = [];

        foreach ($params as $param) {
            if ($param === Generator::UNDEFINED) {
                continue;
            }

            $in = $param->in !== Generator::UNDEFINED ? $param->in : null;

            if ($in !== 'path') {
                continue;
            }

            $examples = $this->buildExamplesFromParameter($param);
            $node = new ParameterNode(
                name: $param->name !== Generator::UNDEFINED
                    ? $param->name
                    : '(unknown)',
                required: $param->required !== Generator::UNDEFINED
                && $param->required === true,
                // @phpstan-ignore nullCoalesce.property (defensive; $schema may be unset at runtime)
                schema: SchemaAccessor::extractSchemaType($param->schema ?? null),
                description: SchemaAccessor::undefinedToNull($param->description),
                // @phpstan-ignore nullCoalesce.property (defensive; $schema may be unset at runtime)
                pattern: SchemaAccessor::extractSchemaPattern($param->schema ?? null),
                examples: $examples,
                raw: $param,
            );

            foreach ($examples as $example) {
                $example->linkParent($node);
            }

            $result[] = $node;
        }

        return $result;
    }

    /**
     * Build examples from a parameter's examples array.
     *
     * @return list<ExampleNode>
     */
    private function buildExamplesFromParameter(OA\Parameter $param): array
    {
        $examples = $param->examples ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)

        if ($examples === Generator::UNDEFINED || !is_array($examples)) {
            return [];
        }

        $result = [];

        foreach ($examples as $example) {
            if ($example === Generator::UNDEFINED) {
                continue;
            }

            if ($example instanceof OA\Examples) {
                $result[] = $this->buildExampleNode($example);
            }
        }

        return $result;
    }

    private function buildExampleNode(OA\Examples $example): ExampleNode
    {
        return new ExampleNode(
            name: $example->example !== Generator::UNDEFINED
                ? $example->example
                : null,
            value: $example->value !== Generator::UNDEFINED
                ? $example->value
                : null,
            summary: SchemaAccessor::undefinedToNull($example->summary),
            description: SchemaAccessor::undefinedToNull($example->description),
            raw: $example,
        );
    }

    /**
     * Extract query parameters from the operation.
     *
     * @return list<QueryParameterNode>
     *
     * @throws LogicException
     */
    private function buildQueryParameters(OA\Operation $operation): array
    {
        $params = $operation->parameters;

        if ($params === Generator::UNDEFINED || !is_array($params)) {
            return [];
        }

        $result = [];

        foreach ($params as $param) {
            if ($param === Generator::UNDEFINED) {
                continue;
            }

            $in = $param->in !== Generator::UNDEFINED ? $param->in : null;

            if ($in !== 'query') {
                continue;
            }

            $examples = $this->buildExamplesFromParameter($param);
            $schema = $param->schema ?? null; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)
            $node = new QueryParameterNode(
                name: $param->name !== Generator::UNDEFINED
                    ? $param->name
                    : '(unknown)',
                required: $param->required !== Generator::UNDEFINED
                && $param->required === true,
                type: SchemaAccessor::extractSchemaType($schema),
                hasSchema: $schema !== null && $schema !== Generator::UNDEFINED,
                style: SchemaAccessor::undefinedToNull($param->style),
                explode: $param->explode !== Generator::UNDEFINED
                    ? (bool) $param->explode
                    : null,
                description: SchemaAccessor::undefinedToNull($param->description),
                enum: SchemaAccessor::extractSchemaEnum($schema),
                examples: $examples,
                raw: $param,
            );

            foreach ($examples as $example) {
                $example->linkParent($node);
            }

            $result[] = $node;
        }

        return $result;
    }

    /**
     * @throws LogicException
     */
    private function buildRequestBody(OA\Operation $operation): ?RequestBodyNode
    {
        $rb = $operation->requestBody;

        if ($rb === Generator::UNDEFINED || $rb === null) {
            return null;
        }

        $contentTypes = [];
        $fields = [];
        $examples = [];
        $schemaRef = null;
        $description = SchemaAccessor::undefinedToNull($rb->description);
        $required
            = $rb->required !== Generator::UNDEFINED && $rb->required === true;

        $content = $rb->content;

        if ($content !== Generator::UNDEFINED && is_array($content)) {
            foreach ($content as $mediaType) {
                if ($mediaType === Generator::UNDEFINED) {
                    continue;
                }

                if ($mediaType instanceof OA\MediaType && $mediaType->mediaType !== Generator::UNDEFINED) {
                    $contentTypes[] = $mediaType->mediaType;
                }

                if ($fields === [] && $schemaRef === null) {
                    $schema = $mediaType->schema ?? null;

                    if ($schema !== null && !is_array($schema) && $schema !== Generator::UNDEFINED) {
                        $ref = SchemaAccessor::extractRef($schema);

                        if ($ref !== null) {
                            $schemaRef = $ref;
                        } elseif ($schema instanceof OA\Schema) {
                            $fields = $this->buildFields($schema);
                        }
                    }
                }

                $mtExamples = $mediaType->examples ?? Generator::UNDEFINED;

                if (
                    $mtExamples !== Generator::UNDEFINED
                    && is_array($mtExamples)
                ) {
                    foreach ($mtExamples as $ex) {
                        if ($ex === Generator::UNDEFINED) {
                            continue;
                        }

                        $examples[] = $this->buildExampleNode($ex);
                    }
                }
            }
        }

        $node = new RequestBodyNode(
            contentTypes: $contentTypes,
            required: $required,
            fields: $fields,
            examples: $examples,
            schemaRef: $schemaRef,
            description: $description,
            raw: $rb,
        );

        // Link field and example parents
        foreach ($fields as $field) {
            $field->linkParent($node);
        }

        foreach ($examples as $example) {
            $example->linkParent($node);
        }

        return $node;
    }

    /**
     * Recursively build field nodes from JSON Schema properties.
     *
     * Resolves `allOf` composition: a schema written as
     * `allOf: [{$ref: '#/components/schemas/Base'}, {properties: {…}}]`
     * exposes both the inherited properties from the `$ref` branch and any
     * properties declared on the local schema. The `required` list is merged
     * the same way. `oneOf` / `anyOf` are intentionally left untouched —
     * they represent alternatives, not composition. Cycles in the `$ref`
     * graph (`A`'s `allOf` references `B` whose `allOf` references `A`) are
     * broken with a visited-set guard keyed by component name; the local
     * declarations on each visited schema still contribute, but the chain
     * stops as soon as the same component is encountered a second time.
     *
     * @param array<string, true> $visited Component names already merged in
     *                                     the current resolution chain.
     *
     * @return list<FieldNode>
     *
     * @throws LogicException
     */
    private function buildFields(?OA\Schema $schema, array $visited = []): array
    {
        if (
            $schema === null
            || $schema === Generator::UNDEFINED // @phpstan-ignore identical.alwaysFalse (defensive; swagger-php may leave the sentinel in place at runtime)
        ) {
            return [];
        }

        [$properties, $required] = $this->collectComposedProperties($schema, $visited);

        if ($properties === []) {
            return [];
        }

        $fields = [];

        foreach ($properties as $name => $property) {
            $children = $this->buildFields($property);
            $examples = $this->buildExamplesFromSchema($property);

            $field = new FieldNode(
                name: $name,
                type: SchemaAccessor::extractSchemaType($property),
                required: in_array($name, $required, true),
                nullable: SchemaAccessor::isNullable($property),
                description: SchemaAccessor::undefinedToNull($property->description),
                format: SchemaAccessor::undefinedToNull($property->format),
                example: $property->example !== Generator::UNDEFINED
                    ? $property->example
                    : null,
                enum: SchemaAccessor::extractSchemaEnum($property),
                children: $children,
                examples: $examples,
                ref: SchemaAccessor::extractRef($property),
                raw: $property instanceof OA\Property ? $property : null,
            );

            // Link children and examples to this field
            foreach ($children as $child) {
                $child->linkParent($field);
            }

            foreach ($examples as $example) {
                $example->linkParent($field);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Collect the merged `(name => Property, list<string> required)` pair for a schema,
     * walking each `allOf` branch and following `$ref`s into the component-schema index
     * with a cycle guard.
     *
     * Property collisions resolve to the inline declaration on the schema being walked
     * — local declarations override allOf-inherited ones (last-writer-wins via array
     * merge order). The `required` list is unioned.
     *
     * @param array<string, true> $visited
     *
     * @return array{0: array<string, OA\Property>, 1: list<string>}
     */
    private function collectComposedProperties(OA\Schema $schema, array $visited): array
    {
        /** @var array<string, OA\Property> $properties */
        $properties = [];
        $required = [];

        $allOf = $schema->allOf;

        if (is_array($allOf)) {
            foreach ($allOf as $branch) {
                if (
                    !$branch instanceof OA\Schema
                    || $branch === Generator::UNDEFINED // @phpstan-ignore identical.alwaysFalse (defensive; swagger-php may leave the sentinel in place at runtime)
                ) {
                    continue;
                }

                $ref = SchemaAccessor::extractRef($branch);

                if ($ref !== null) {
                    if (isset($visited[$ref])) {
                        continue;
                    }

                    $target = $this->componentSchemaIndex[$ref] ?? null;

                    if ($target === null) {
                        continue;
                    }

                    [$inherited, $branchRequired] = $this->collectComposedProperties(
                        $target,
                        [...$visited, $ref => true],
                    );

                    foreach ($inherited as $name => $property) {
                        $properties[$name] = $property;
                    }

                    foreach ($branchRequired as $name) {
                        $required[] = $name;
                    }

                    continue;
                }

                [$inherited, $branchRequired] = $this->collectComposedProperties($branch, $visited);

                foreach ($inherited as $name => $property) {
                    $properties[$name] = $property;
                }

                foreach ($branchRequired as $name) {
                    $required[] = $name;
                }
            }
        }

        $localProperties = $schema->properties;

        if (is_array($localProperties)) {
            foreach ($localProperties as $property) {
                if (
                    !$property instanceof OA\Property
                    || $property === Generator::UNDEFINED // @phpstan-ignore identical.alwaysFalse (defensive; swagger-php may leave the sentinel in place at runtime)
                ) {
                    continue;
                }

                $name = $property->property !== Generator::UNDEFINED
                    ? $property->property
                    : '(unknown)';

                $properties[$name] = $property;
            }
        }

        if ($schema->required !== Generator::UNDEFINED && is_array($schema->required)) {
            foreach ($schema->required as $name) {
                $required[] = $name;
            }
        }

        return [$properties, array_values(array_unique($required))];
    }

    /**
     * Build examples from a schema's examples.
     *
     * @return list<ExampleNode>
     */
    private function buildExamplesFromSchema(OA\Schema $schema): array
    {
        $examples = $schema->examples ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)

        if ($examples === Generator::UNDEFINED || !is_array($examples)) {
            return [];
        }

        $result = [];

        foreach ($examples as $example) {
            if ($example === Generator::UNDEFINED) {
                continue;
            }

            if ($example instanceof OA\Examples) {
                $result[] = $this->buildExampleNode($example);
            }
        }

        return $result;
    }

    /**
     * @return list<ResponseNode>
     *
     * @throws LogicException
     */
    private function buildResponses(OA\Operation $operation): array
    {
        $responses = $operation->responses;

        if ($responses === Generator::UNDEFINED || !is_array($responses)) {
            return [];
        }

        $result = [];

        foreach ($responses as $response) {
            if ($response === Generator::UNDEFINED) {
                continue;
            }

            $statusCode
                = $response->response !== Generator::UNDEFINED
                ? $response->response
                : 'default';

            $description = SchemaAccessor::undefinedToNull($response->description);
            $fields = [];
            $examples = [];
            $schemaRef = null;
            $headers = [];
            $links = [];

            $content = $response->content ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)

            if ($content !== Generator::UNDEFINED && is_array($content)) {
                foreach ($content as $mediaType) {
                    if ($mediaType === Generator::UNDEFINED) {
                        continue;
                    }

                    if ($fields === [] && $schemaRef === null) {
                        $schema = $mediaType->schema ?? null;

                        if (
                            $schema !== null
                            && !is_array($schema)
                            && $schema !== Generator::UNDEFINED
                        ) {
                            $ref = SchemaAccessor::extractRef($schema);

                            if ($ref !== null) {
                                $schemaRef = $ref;
                            } elseif ($schema instanceof OA\Schema) {
                                $fields = $this->buildFields($schema);
                            }
                        }
                    }

                    $mtExamples = $mediaType->examples ?? Generator::UNDEFINED;

                    if (
                        $mtExamples !== Generator::UNDEFINED
                        && is_array($mtExamples)
                    ) {
                        foreach ($mtExamples as $ex) {
                            if ($ex === Generator::UNDEFINED) {
                                continue;
                            }

                            $examples[] = $this->buildExampleNode($ex);
                        }
                    }
                }
            }

            $oaHeaders = $response->headers ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)

            if ($oaHeaders !== Generator::UNDEFINED && is_array($oaHeaders)) {
                foreach ($oaHeaders as $header) {
                    if ($header === Generator::UNDEFINED) {
                        continue;
                    }

                    $headers[] = $this->buildHeader($header);
                }
            }

            $oaLinks = $response->links ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)

            if ($oaLinks !== Generator::UNDEFINED && is_array($oaLinks)) {
                foreach ($oaLinks as $link) {
                    if ($link === Generator::UNDEFINED) {
                        continue;
                    }

                    $links[] = $this->buildLink($link);
                }
            }

            $node = new ResponseNode(
                statusCode: $statusCode,
                description: $description,
                fields: $fields,
                examples: $examples,
                schemaRef: $schemaRef,
                headers: $headers,
                links: $links,
                raw: $response,
            );

            // Link children
            foreach ($fields as $field) {
                $field->linkParent($node);
            }

            foreach ($examples as $example) {
                $example->linkParent($node);
            }

            foreach ($headers as $header) {
                $header->linkParent($node);
            }

            foreach ($links as $link) {
                $link->linkParent($node);
            }

            $result[] = $node;
        }

        return $result;
    }

    private function buildHeader(OA\Header $header): HeaderNode
    {
        return new HeaderNode(
            name: $header->header !== Generator::UNDEFINED
                ? $header->header
                : '(unknown)',
            // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)
            schema: SchemaAccessor::extractSchemaType($header->schema ?? null),
            description: SchemaAccessor::undefinedToNull($header->description),
            required: $header->required !== Generator::UNDEFINED
            && $header->required === true,
            raw: $header,
        );
    }

    private function buildLink(OA\Link $link): LinkNode
    {
        $parameters = [];
        $oaParams = $link->parameters;

        if ($oaParams !== Generator::UNDEFINED && is_array($oaParams)) {
            foreach ($oaParams as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $parameters[$key] = $value;
                }
            }
        }

        return new LinkNode(
            name: $link->link !== Generator::UNDEFINED
                ? $link->link
                : '(unnamed)',
            operationId: SchemaAccessor::undefinedToNull($link->operationId),
            operationRef: SchemaAccessor::undefinedToNull($link->operationRef),
            parameters: $parameters,
            description: SchemaAccessor::undefinedToNull($link->description),
            raw: $link,
        );
    }

    /**
     * @return list<array{scheme: string, scopes: list<string>}>
     */
    private function buildSecurity(OA\Operation $operation): array
    {
        $security = $operation->security;

        if ($security === Generator::UNDEFINED || !is_array($security)) {
            return [];
        }

        $result = [];

        foreach ($security as $requirement) {
            if ($requirement === Generator::UNDEFINED) {
                continue;
            }

            // OA\SecurityScheme annotation — varies by swagger-php version
            if ($requirement instanceof OA\SecurityScheme) {
                $scheme
                    = $requirement->securityScheme !== Generator::UNDEFINED
                    ? $requirement->securityScheme
                    : '(unknown)';
                $result[] = ['scheme' => $scheme, 'scopes' => []];
            } elseif (is_array($requirement)) {
                foreach ($requirement as $scheme => $scopes) {
                    $result[] = [
                        'scheme' => (string) $scheme,
                        'scopes' => is_array($scopes)
                            ? array_values(array_map('strval', $scopes))
                            : [],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function buildOperationTags(OA\Operation $operation): array
    {
        $tags = $operation->tags;

        if ($tags === Generator::UNDEFINED || !is_array($tags)) {
            return [];
        }

        return array_values(
            array_filter(
                $tags,
                // @phpstan-ignore function.alreadyNarrowedType (defensive; OA\Operation::$tags may contain non-strings at runtime)
                static fn($tag): bool => is_string($tag),
            ),
        );
    }

    /**
     * @return list<ComponentSchemaNode>
     *
     * @throws LogicException
     */
    private function buildComponents(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if ($components === Generator::UNDEFINED || $components === null) {
            return [];
        }

        $schemas = $components->schemas;

        if ($schemas === Generator::UNDEFINED || !is_array($schemas)) {
            return [];
        }

        $result = [];

        foreach ($schemas as $schema) {
            if ($schema === Generator::UNDEFINED) {
                continue;
            }

            $name
                = $schema->schema !== Generator::UNDEFINED
                ? $schema->schema
                : '(unknown)';

            $description = SchemaAccessor::undefinedToNull($schema->description);
            $fields = $this->buildFields($schema);

            $node = new ComponentSchemaNode(
                name: $name,
                description: $description,
                fields: $fields,
                raw: $schema,
                sourceClass: $this->componentClassMap[$name] ?? null,
            );

            foreach ($fields as $field) {
                $field->linkParent($node);
            }

            $result[] = $node;
        }

        return $result;
    }

    /**
     * @return list<WebhookNode>
     *
     * @throws LogicException
     */
    private function buildWebhooks(OA\OpenApi $spec): array
    {
        $webhooks = $spec->webhooks ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)

        if ($webhooks === Generator::UNDEFINED || !is_array($webhooks)) {
            return [];
        }

        $result = [];

        foreach ($webhooks as $name => $pathItem) {
            if ($pathItem === Generator::UNDEFINED) {
                continue;
            }

            $webhookName = is_string($name) ? $name : '(unknown)';

            $description = SchemaAccessor::undefinedToNull(
                $pathItem->description ?? Generator::UNDEFINED, // @phpstan-ignore nullCoalesce.property (defensive; swagger-php may leave property unset at runtime)
            );

            foreach (self::HTTP_METHODS as $method) {
                $oaOperation = $pathItem->{$method} ?? null;

                if (
                    $oaOperation === Generator::UNDEFINED
                    || $oaOperation === null
                ) {
                    continue;
                }

                $operation = $this->buildOperation(
                    $oaOperation,
                    $webhookName,
                    strtoupper($method),
                    null,
                    webhook: true,
                );

                $webhook = new WebhookNode(
                    name: $webhookName,
                    description: $description,
                    operation: $operation,
                    raw: $pathItem,
                );

                $operation->linkParent($webhook);
                $result[] = $webhook;
            }
        }

        return $result;
    }

    /**
     * @return array{list<string>, array<string, string>}
     */
    private function buildTags(OA\OpenApi $spec): array
    {
        $tags = $spec->tags;

        if ($tags === Generator::UNDEFINED || !is_array($tags)) {
            return [[], []];
        }

        $names = [];
        $descriptions = [];

        foreach ($tags as $tag) {
            if ($tag === Generator::UNDEFINED) {
                continue;
            }

            $name = $tag->name !== Generator::UNDEFINED ? $tag->name : null;

            if ($name === null) {
                continue;
            }

            $names[] = $name;
            $desc
                = $tag->description !== Generator::UNDEFINED
                ? $tag->description
                : null;

            if ($desc !== null) {
                $descriptions[$name] = $desc;
            }
        }

        return [$names, $descriptions];
    }

}
