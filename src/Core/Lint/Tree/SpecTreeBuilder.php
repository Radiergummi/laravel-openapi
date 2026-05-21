<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
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
     * Build the domain tree from an OpenAPI spec and action descriptors.
     *
     * @param list<ActionDescriptor> $actionDescriptors
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
            operationId: $this->undefinedToNull($oaOperation->operationId),
            summary: $this->undefinedToNull($oaOperation->summary),
            description: $this->undefinedToNull($oaOperation->description),
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
        foreach ($parameters as $param) {
            $param->linkParent($operation);
        }

        foreach ($queryParameters as $qp) {
            $qp->linkParent($operation);
        }

        if ($requestBody !== null) {
            $requestBody->linkParent($operation);
        }

        foreach ($responses as $response) {
            $response->linkParent($operation);
        }

        return $operation;
    }

    /**
     * Extract path parameters from the operation.
     *
     * @return list<ParameterNode>
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
                schema: $this->extractSchemaType($param->schema ?? null), // @phpstan-ignore nullCoalesce.property
                description: $this->undefinedToNull($param->description),
                pattern: $this->extractSchemaPattern($param->schema ?? null), // @phpstan-ignore nullCoalesce.property
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
     * Extract query parameters from the operation.
     *
     * @return list<QueryParameterNode>
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
            $schema = $param->schema ?? null; // @phpstan-ignore nullCoalesce.property
            $node = new QueryParameterNode(
                name: $param->name !== Generator::UNDEFINED
                    ? $param->name
                    : '(unknown)',
                required: $param->required !== Generator::UNDEFINED
                && $param->required === true,
                type: $this->extractSchemaType($schema),
                hasSchema: $schema !== null && $schema !== Generator::UNDEFINED,
                style: $this->undefinedToNull($param->style),
                explode: $param->explode !== Generator::UNDEFINED
                    ? (bool) $param->explode
                    : null,
                description: $this->undefinedToNull($param->description),
                enum: $this->extractSchemaEnum($schema),
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
        $description = $this->undefinedToNull($rb->description);
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

                // Extract schema fields from the first media type that has a schema
                if ($fields === [] && $schemaRef === null) {
                    $schema = $mediaType->schema ?? null;

                    if ($schema !== null && !is_array($schema) && $schema !== Generator::UNDEFINED) {
                        $ref = $this->extractRef($schema);

                        if ($ref !== null) {
                            $schemaRef = $ref;
                        } elseif ($schema instanceof OA\Schema) {
                            $fields = $this->buildFields($schema);
                        }
                    }
                }

                // Extract body-level examples from media type
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
     * @return list<ResponseNode>
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

            $description = $this->undefinedToNull($response->description);
            $fields = [];
            $examples = [];
            $schemaRef = null;
            $headers = [];
            $links = [];

            // Extract schema from response content
            $content = $response->content ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

            if ($content !== Generator::UNDEFINED && is_array($content)) {
                foreach ($content as $mediaType) {
                    if ($mediaType === Generator::UNDEFINED) {
                        continue;
                    }

                    // Extract fields from the first media type with a schema
                    if ($fields === [] && $schemaRef === null) {
                        $schema = $mediaType->schema ?? null;

                        if (
                            $schema !== null
                            && !is_array($schema)
                            && $schema !== Generator::UNDEFINED
                        ) {
                            $ref = $this->extractRef($schema);

                            if ($ref !== null) {
                                $schemaRef = $ref;
                            } elseif ($schema instanceof OA\Schema) {
                                $fields = $this->buildFields($schema);
                            }
                        }
                    }

                    // Extract body-level examples
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

            // Extract headers
            $oaHeaders = $response->headers ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

            if ($oaHeaders !== Generator::UNDEFINED && is_array($oaHeaders)) {
                foreach ($oaHeaders as $header) {
                    if ($header === Generator::UNDEFINED) {
                        continue;
                    }

                    $headers[] = $this->buildHeader($header);
                }
            }

            // Extract links
            $oaLinks = $response->links ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

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
        // @phpstan-ignore-next-line identical.alwaysFalse
        if ($schema === null || $schema === Generator::UNDEFINED) {
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
                type: $this->extractSchemaType($property),
                required: in_array($name, $required, true),
                nullable: $this->isNullable($property),
                description: $this->undefinedToNull($property->description),
                format: $this->undefinedToNull($property->format),
                example: $property->example !== Generator::UNDEFINED
                    ? $property->example
                    : null,
                enum: $this->extractSchemaEnum($property),
                children: $children,
                examples: $examples,
                ref: $this->extractRef($property),
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
                // @phpstan-ignore-next-line identical.alwaysFalse
                if (!$branch instanceof OA\Schema || $branch === Generator::UNDEFINED) {
                    continue;
                }

                $ref = $this->extractRef($branch);

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
                // @phpstan-ignore-next-line identical.alwaysFalse
                if (!$property instanceof OA\Property || $property === Generator::UNDEFINED) {
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
                // @phpstan-ignore-next-line identical.alwaysFalse
                !$schema instanceof OA\Schema || $schema === Generator::UNDEFINED
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
     * @return list<ComponentSchemaNode>
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

            $description = $this->undefinedToNull($schema->description);
            $fields = $this->buildFields($schema);

            $node = new ComponentSchemaNode(
                name: $name,
                description: $description,
                fields: $fields,
                raw: $schema,
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
     */
    private function buildWebhooks(OA\OpenApi $spec): array
    {
        $webhooks = $spec->webhooks ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

        if ($webhooks === Generator::UNDEFINED || !is_array($webhooks)) {
            return [];
        }

        $result = [];

        foreach ($webhooks as $name => $pathItem) {
            if ($pathItem === Generator::UNDEFINED) {
                continue;
            }

            $webhookName = is_string($name) ? $name : '(unknown)';

            $description = $this->undefinedToNull(
                $pathItem->description ?? Generator::UNDEFINED, // @phpstan-ignore nullCoalesce.property
            );

            // Build one WebhookNode per HTTP method defined on this path item
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
                static fn($tag): bool => is_string($tag), // @phpstan-ignore function.alreadyNarrowedType
            ),
        );
    }

    private function buildHeader(OA\Header $header): HeaderNode
    {
        return new HeaderNode(
            name: $header->header !== Generator::UNDEFINED
                ? $header->header
                : '(unknown)',
            schema: $this->extractSchemaType($header->schema ?? null), // @phpstan-ignore nullCoalesce.property
            description: $this->undefinedToNull($header->description),
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
            operationId: $this->undefinedToNull($link->operationId),
            operationRef: $this->undefinedToNull($link->operationRef),
            parameters: $parameters,
            description: $this->undefinedToNull($link->description),
            raw: $link,
        );
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
            summary: $this->undefinedToNull($example->summary),
            description: $this->undefinedToNull($example->description),
            raw: $example,
        );
    }

    /**
     * Build examples from a parameter's examples array.
     *
     * @return list<ExampleNode>
     */
    private function buildExamplesFromParameter(OA\Parameter $param): array
    {
        $examples = $param->examples ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

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
     * Build examples from a schema's examples.
     *
     * @return list<ExampleNode>
     */
    private function buildExamplesFromSchema(OA\Schema $schema): array
    {
        $examples = $schema->examples ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

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
     * Extract a $ref component name from a schema.
     * Returns the component name (e.g., "User") or null if not a $ref.
     *
     * @param null|array<string, mixed>|OA\Schema|string $schema
     */
    private function extractRef(OA\Schema|array|string|null $schema): ?string
    {
        if ($schema === null || $schema === Generator::UNDEFINED) {
            return null;
        }

        $ref = $schema->ref ?? Generator::UNDEFINED;

        if (
            $ref === Generator::UNDEFINED
            || $ref === null // @phpstan-ignore identical.alwaysFalse
            || !is_string($ref)
        ) {
            return null;
        }

        if (preg_match('~^#/components/schemas/(.+)$~', $ref, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractSchemaType(mixed $schema): ?string
    {
        if ($schema === null || $schema === Generator::UNDEFINED) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $type = $schema->type;

        if ($type === Generator::UNDEFINED || $type === null) {
            return null;
        }

        // OAS 3.1 allows `type` to be an array (e.g. ["string", "null"]).
        // Collapse it to the first concrete (non-"null") type so downstream
        // rules can still reason about the field.
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }

            return null;
        }

        return is_string($type) ? $type : null;
    }

    private function extractSchemaPattern(mixed $schema): ?string
    {
        if ($schema === null || $schema === Generator::UNDEFINED) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $pattern = $schema->pattern ?? Generator::UNDEFINED; // @phpstan-ignore nullCoalesce.property

        // @phpstan-ignore-next-line identical.alwaysFalse
        if ($pattern === Generator::UNDEFINED || $pattern === null) {
            return null;
        }

        return is_string($pattern) ? $pattern : null;
    }

    /**
     * @return null|list<mixed>
     */
    private function extractSchemaEnum(mixed $schema): ?array
    {
        if ($schema === null || $schema === Generator::UNDEFINED) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $enum = $schema->enum;

        if ($enum === Generator::UNDEFINED || !is_array($enum)) {
            return null;
        }

        return array_values($enum);
    }

    private function isNullable(OA\Schema $schema): bool
    {
        // OAS 3.0 style
        if (
            $schema->nullable !== Generator::UNDEFINED
            && $schema->nullable === true
        ) {
            return true;
        }

        // OAS 3.1 style (type as array including "null")
        $type = $schema->type;

        if (is_array($type) && in_array('null', $type, true)) {
            return true;
        }

        return false;
    }

    private function undefinedToNull(mixed $value): ?string
    {
        if ($value === Generator::UNDEFINED || $value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
