<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function strtoupper;

/**
 * Transforms the raw `OA\OpenApi` annotation graph into a clean, typed domain tree.
 */
final class SpecTreeBuilder
{
    /**
     * Component schemas indexed by name — populated at the start of {@see build()} so
     * {@see buildFields()} can resolve `allOf: [{$ref: …}]` branches to the underlying properties.
     *
     * @var array<string, OA\Schema>
     */
    private array $componentSchemaIndex = [];

    /**
     * Component responses indexed by name — populated at the start of {@see build()} so
     * {@see buildResponses()} can dereference a `$ref` response
     * (`$ref: '#/components/responses/{name}'`) to the description carried by the referenced
     * component, which the local response node lacks.
     *
     * @var array<string, OA\Response>
     */
    private array $componentResponseIndex = [];

    /**
     * @param array<string, class-string> $componentClassMap Component key → originating PHP class,
     *                                                       as returned by
     *                                                       {@see ComponentSchemaRegistry::componentClassMap()}.
     *                                                       Used to populate
     *                                                       {@see ComponentSchemaNode::$sourceClass}.
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
        $this->componentResponseIndex = $this->indexComponentResponses($spec);

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

        if ($components === null || is_undefined($components)) {
            return [];
        }

        $schemas = $components->schemas;

        if (!is_array($schemas) || is_undefined($schemas)) {
            return [];
        }

        $index = [];

        foreach ($schemas as $schema) {
            if (
                !$schema instanceof OA\Schema
                || is_undefined($schema)
            ) {
                continue;
            }

            if (is_undefined($schema->schema)) {
                continue;
            }

            $index[$schema->schema] = $schema;
        }

        return $index;
    }

    /**
     * Index `components.responses` by component name so {@see buildResponses()} can dereference
     * `$ref` responses. The component name is carried on each response's `response` property,
     * mirroring how {@see indexComponentSchemas()} keys off `$schema->schema`.
     *
     * @return array<string, OA\Response>
     */
    private function indexComponentResponses(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if ($components === null || is_undefined($components)) {
            return [];
        }

        $responses = $components->responses;

        if (!is_array($responses) || is_undefined($responses)) {
            return [];
        }

        $index = [];

        foreach ($responses as $response) {
            if (
                !$response instanceof OA\Response
                || is_undefined($response)
            ) {
                continue;
            }

            if (is_undefined($response->response)) {
                continue;
            }

            $index[(string) $response->response] = $response;
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
            if (is_undefined($path)) {
                continue;
            }

            $pathUri
                = is_defined($path->path)
                ? $path->path
                : '(unknown)';

            foreach (HttpMethod::cases() as $method) {
                $oaOperation = $path->{$method->value} ?? null;

                if ($oaOperation === null || is_undefined($oaOperation)) {
                    continue;
                }

                $upperMethod = strtoupper($method->value);
                $descriptorKey = "{$upperMethod} {$pathUri}";
                $descriptor = $descriptorIndex[$descriptorKey] ?? null;

                $operations[] = $this->buildOperation(
                    $oaOperation,
                    $pathUri,
                    $method,
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
        HttpMethod $method,
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
            deprecated: $oaOperation->deprecated === true,
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

        if (!is_array($params) || is_undefined($params)) {
            return [];
        }

        $result = [];

        foreach ($params as $param) {
            if (is_undefined($param)) {
                continue;
            }

            $in = is_defined($param->in) ? $param->in : null;

            if ($in !== 'path') {
                continue;
            }

            // Latent: a `$ref`'d parameter would carry no inline description and false-fire
            // `parameter.description-missing`, like the response-ref bug fixed in this class. The
            // generator never emits `components.parameters`/`$ref` parameters today (parameters are
            // always inlined), so no dereference is wired in. Add one here if that changes.
            $examples = NodeFactory::examplesFromParameter($param);
            $node = new ParameterNode(
                name: is_defined($param->name)
                    ? $param->name
                    : '(unknown)',
                required: is_defined($param->required)
                && $param->required === true,
                // @phpstan-ignore nullCoalesce.property ($schema may be unset at runtime)
                schema: SchemaAccessor::extractSchemaType($param->schema ?? null),
                description: SchemaAccessor::undefinedToNull($param->description),
                // @phpstan-ignore nullCoalesce.property ($schema may be unset at runtime)
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
     * Extract query parameters from the operation.
     *
     * @return list<QueryParameterNode>
     *
     * @throws LogicException
     */
    private function buildQueryParameters(OA\Operation $operation): array
    {
        $params = $operation->parameters;

        if (!is_array($params) || is_undefined($params)) {
            return [];
        }

        $result = [];

        foreach ($params as $param) {
            if (is_undefined($param)) {
                continue;
            }

            $in = is_defined($param->in) ? $param->in : null;

            if ($in !== 'query') {
                continue;
            }

            $examples = NodeFactory::examplesFromParameter($param);
            // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
            $schema = $param->schema ?? null;
            $node = new QueryParameterNode(
                name: is_defined($param->name)
                    ? $param->name
                    : '(unknown)',
                required: is_defined($param->required)
                && $param->required === true,
                type: SchemaAccessor::extractSchemaType($schema),
                hasSchema: $schema !== null && is_defined($schema),
                style: SchemaAccessor::undefinedToNull($param->style),
                explode: is_defined($param->explode)
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
        $requestBody = $operation->requestBody;

        if ($requestBody === null || is_undefined($requestBody)) {
            return null;
        }

        $contentTypes = [];
        $fields = [];
        $examples = [];
        $schemaRef = null;
        $description = SchemaAccessor::undefinedToNull($requestBody->description);
        $required = $requestBody->required === true;

        $content = $requestBody->content;

        if (is_array($content) && is_defined($content)) {
            foreach ($content as $mediaType) {
                if (is_undefined($mediaType)) {
                    continue;
                }

                if ($mediaType instanceof OA\MediaType && is_defined($mediaType->mediaType)) {
                    $contentTypes[] = $mediaType->mediaType;
                }

                if ($fields === [] && $schemaRef === null) {
                    $schema = $mediaType->schema ?? null;

                    if ($schema !== null && !is_array($schema) && is_defined($schema)) {
                        $ref = SchemaAccessor::extractRef($schema);

                        if ($ref !== null) {
                            $schemaRef = $ref;
                        } elseif ($schema instanceof OA\Schema) {
                            $fields = $this->buildFields($schema);
                        }
                    }
                }

                $mediaTypeExamples = $mediaType->examples ?? Generator::UNDEFINED;

                if (is_array($mediaTypeExamples) && is_defined($mediaTypeExamples)) {
                    foreach ($mediaTypeExamples as $mediaTypeExample) {
                        if (is_undefined($mediaTypeExample)) {
                            continue;
                        }

                        $examples[] = NodeFactory::exampleNode($mediaTypeExample);
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
            raw: $requestBody,
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
     * `allOf: [{$ref: '#/components/schemas/Base'}, {properties: {…}}]` exposes both the inherited
     * properties from the `$ref` branch and any properties declared on the local schema.
     * The `required` list is merged the same way. `oneOf` / `anyOf` on a *property* are handled
     * per-field below: the standard nullable shape is unwrapped to its concrete branch, while a
     * genuine multi-alternative union is flagged via `FieldNode::$uninspectedCompositeBranches`
     * (no field union is attempted). Cycles in the `$ref` graph (`A`'s
     * `allOf` references `B` whose `allOf` references `A`) are broken with a visited-set guard
     * keyed by component name; the local declarations on each visited schema still contribute, but
     * the chain stops as soon as the same component is encountered a second time.
     *
     * @param array<string, true> $visited Component names already merged in the current
     *                                     resolution chain.
     *
     * @return list<FieldNode>
     *
     * @throws LogicException
     */
    private function buildFields(?OA\Schema $schema, array $visited = []): array
    {
        if (
            $schema === null
            || is_undefined($schema)
        ) {
            return [];
        }

        [$properties, $required] = $this->collectComposedProperties($schema, $visited);

        if ($properties === []) {
            return [];
        }

        $fields = [];

        foreach ($properties as $name => $property) {
            // Resolve a `oneOf`/`anyOf` property. The standard nullable shape (one concrete branch
            // plus `{type: 'null'}`) is unwrapped to its concrete branch so field/schema rules still
            // inspect the underlying type and nested properties. A genuine union of multiple
            // alternatives is left uninspected and flagged instead — see SchemaCompositeFieldsUninspected.
            $composition = SchemaAccessor::classifyComposition($property);
            $structural = $composition['branch'] ?? $property;
            $nullable = SchemaAccessor::isNullable($property)
                || $composition['branch'] !== null;

            $children = $this->buildFields($structural);
            $examples = NodeFactory::examplesFromSchema($property);

            $field = new FieldNode(
                name: $name,
                type: SchemaAccessor::extractSchemaType($structural),
                required: in_array($name, $required, true),
                nullable: $nullable,
                description: SchemaAccessor::undefinedToNull($property->description),
                format: SchemaAccessor::undefinedToNull($property->format),
                example: is_defined($property->example)
                    ? $property->example
                    : null,
                enum: SchemaAccessor::extractSchemaEnum($structural),
                children: $children,
                examples: $examples,
                ref: SchemaAccessor::extractRef($structural),
                raw: $property instanceof OA\Property ? $property : null,
                uninspectedCompositeBranches: $composition['uninspectedComposite'],
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
     * Collect the merged `(name => Property, list<string> required)` pair for a schema, walking
     * each `allOf` branch and following `$ref`s into the component-schema index with a cycle guard.
     *
     * Property collisions resolve to the inline declaration on the schema being walked — local
     * declarations override allOf-inherited ones (last-writer-wins via array merge order).
     * The `required` list is union-ed.
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
                if (!$branch instanceof OA\Schema || is_undefined($branch)) {
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

                [$inherited, $branchRequired] = $this->collectComposedProperties(
                    $branch,
                    $visited,
                );

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
                if (!$property instanceof OA\Property || is_undefined($property)) {
                    continue;
                }

                $name = is_defined($property->property)
                    ? $property->property
                    : '(unknown)';

                $properties[$name] = $property;
            }
        }

        if (is_array($schema->required) && is_defined($schema->required)) {
            foreach ($schema->required as $name) {
                $required[] = $name;
            }
        }

        return [$properties, array_values(array_unique($required))];
    }

    /**
     * @return list<ResponseNode>
     *
     * @throws LogicException
     */
    private function buildResponses(OA\Operation $operation): array
    {
        $responses = $operation->responses;

        if (!is_array($responses) || is_undefined($responses)) {
            return [];
        }

        $result = [];

        foreach ($responses as $response) {
            if (is_undefined($response)) {
                continue;
            }

            $statusCode
                = is_defined($response->response)
                ? $response->response
                : 'default';

            $description = SchemaAccessor::undefinedToNull($response->description);

            // A response emitted as a Reference Object (`$ref: '#/components/responses/{name}'`)
            // carries no inline description — the referenced component does. Dereference it so
            // description rules see the real text instead of false-firing. Only fill in when the
            // local node has none of its own.
            if ($description === null) {
                $description = $this->resolveReferencedResponseDescription($response);
            }

            $fields = [];
            $examples = [];
            $schemaRef = null;
            $headers = [];
            $links = [];

            // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
            $content = $response->content ?? Generator::UNDEFINED;

            if (is_array($content) && is_defined($content)) {
                foreach ($content as $mediaType) {
                    if (is_undefined($mediaType)) {
                        continue;
                    }

                    if ($fields === [] && $schemaRef === null) {
                        $schema = $mediaType->schema ?? null;

                        if (
                            $schema !== null
                            && !is_array($schema)
                            && is_defined($schema)
                        ) {
                            $ref = SchemaAccessor::extractRef($schema);

                            if ($ref !== null) {
                                $schemaRef = $ref;
                            } elseif ($schema instanceof OA\Schema) {
                                $fields = $this->buildFields($schema);
                            }
                        }
                    }

                    $mediaTypeExamples = $mediaType->examples ?? Generator::UNDEFINED;

                    if (
                        is_array($mediaTypeExamples)
                        && is_defined($mediaTypeExamples)
                    ) {
                        foreach ($mediaTypeExamples as $mediaTypeExample) {
                            if (is_undefined($mediaTypeExample)) {
                                continue;
                            }

                            $examples[] = NodeFactory::exampleNode($mediaTypeExample);
                        }
                    }
                }
            }

            // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
            $oaHeaders = $response->headers ?? Generator::UNDEFINED;

            if (is_array($oaHeaders) && is_defined($oaHeaders)) {
                foreach ($oaHeaders as $header) {
                    if (is_undefined($header)) {
                        continue;
                    }

                    $headers[] = NodeFactory::header($header);
                }
            }

            // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
            $oaLinks = $response->links ?? Generator::UNDEFINED;

            if (is_array($oaLinks) && is_defined($oaLinks)) {
                foreach ($oaLinks as $link) {
                    if (is_undefined($link)) {
                        continue;
                    }

                    $links[] = NodeFactory::link($link);
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
     * Resolve the description of a `$ref` response from the indexed component it points at.
     * Returns null when the response is not a ref or the ref is dangling — a dangling ref is the
     * `ref.broken` rule's concern, not a description-rule's, so we leave the description absent
     * rather than crash or silently satisfy the check.
     */
    private function resolveReferencedResponseDescription(OA\Response $response): ?string
    {
        $name = SchemaAccessor::extractResponseRef($response);

        if ($name === null) {
            return null;
        }

        $target = $this->componentResponseIndex[$name] ?? null;

        if ($target === null) {
            return null;
        }

        return SchemaAccessor::undefinedToNull($target->description);
    }

    /**
     * @return list<array{scheme: string, scopes: list<string>}>
     */
    private function buildSecurity(OA\Operation $operation): array
    {
        $security = $operation->security;

        if (!is_array($security) || is_undefined($security)) {
            return [];
        }

        $result = [];

        foreach ($security as $requirement) {
            if (is_undefined($requirement)) {
                continue;
            }

            // OA\SecurityScheme annotation — varies by swagger-php version
            if ($requirement instanceof OA\SecurityScheme) {
                $scheme
                    = is_defined($requirement->securityScheme)
                    ? $requirement->securityScheme
                    : '(unknown)';
                $result[] = ['scheme' => $scheme, 'scopes' => []];
            } elseif (is_array($requirement)) {
                foreach ($requirement as $scheme => $scopes) {
                    $result[] = [
                        'scheme' => (string) $scheme,
                        'scopes' => is_array($scopes)
                            ? array_values(array_map(strval(...), $scopes))
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

        if (!is_array($tags) || is_undefined($tags)) {
            return [];
        }

        return array_values(
            array_filter(
                $tags,
                // @phpstan-ignore function.alreadyNarrowedType (OA\Operation::$tags may contain non-strings at runtime)
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

        if ($components === null || is_undefined($components)) {
            return [];
        }

        $schemas = $components->schemas;

        if (!is_array($schemas) || is_undefined($schemas)) {
            return [];
        }

        $result = [];

        foreach ($schemas as $schema) {
            if (is_undefined($schema)) {
                continue;
            }

            $name
                = is_defined($schema->schema)
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
        // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
        $webhooks = $spec->webhooks ?? Generator::UNDEFINED;

        if (!is_array($webhooks) || is_undefined($webhooks)) {
            return [];
        }

        $result = [];

        foreach ($webhooks as $name => $pathItem) {
            if (is_undefined($pathItem)) {
                continue;
            }

            $webhookName = is_string($name) ? $name : '(unknown)';

            $description = SchemaAccessor::undefinedToNull(
                // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
                $pathItem->description ?? Generator::UNDEFINED,
            );

            foreach (HttpMethod::cases() as $method) {
                $oaOperation = $pathItem->{$method->value} ?? null;

                if ($oaOperation === null || is_undefined($oaOperation)) {
                    continue;
                }

                $operation = $this->buildOperation(
                    $oaOperation,
                    $webhookName,
                    $method,
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

        if (!is_array($tags) || is_undefined($tags)) {
            return [[], []];
        }

        $names = [];
        $descriptions = [];

        foreach ($tags as $tag) {
            if (is_undefined($tag)) {
                continue;
            }

            $name = is_defined($tag->name) ? $tag->name : null;

            if ($name === null) {
                continue;
            }

            $names[] = $name;
            $desc
                = is_defined($tag->description)
                ? $tag->description
                : null;

            if ($desc !== null) {
                $descriptions[$name] = $desc;
            }
        }

        return [$names, $descriptions];
    }

}
