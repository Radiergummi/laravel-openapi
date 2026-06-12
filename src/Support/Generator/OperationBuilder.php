<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Deprecated as NativeDeprecated;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Attributes\BaseExample as BaseExampleAttribute;
use Radiergummi\OpenApi\Attributes\Deprecated as DeprecatedAttribute;
use Radiergummi\OpenApi\Attributes\Description as DescriptionAttribute;
use Radiergummi\OpenApi\Attributes\Example as ExampleAttribute;
use Radiergummi\OpenApi\Attributes\ExternalDocs as ExternalDocsAttribute;
use Radiergummi\OpenApi\Attributes\Header as HeaderAttribute;
use Radiergummi\OpenApi\Attributes\Link as LinkAttribute;
use Radiergummi\OpenApi\Attributes\Operation as OperationAttribute;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\RequestBody as RequestBodyAttribute;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\ResponseExample as ResponseExampleAttribute;
use Radiergummi\OpenApi\Attributes\ResponseHeader as ResponseHeaderAttribute;
use Radiergummi\OpenApi\Attributes\Security as SecurityAttribute;
use Radiergummi\OpenApi\Attributes\Summary as SummaryAttribute;
use Radiergummi\OpenApi\Attributes\Tag as TagAttribute;
use Radiergummi\OpenApi\Contracts\Registry\OperationConvention;
use Radiergummi\OpenApi\Contracts\Registry\OperationConventionResolver;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\RequestBodyExtractor;
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;
use Radiergummi\OpenApi\Support\Extraction\UriParametersExtractor;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use ReflectionAttribute;
use ReflectionException;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Builds the property array {@see OpenApiGenerator} dispatches onto OA\Get/OA\Post/etc.
 * Override precedence: auto-derived defaults → class `#[Operation]` → method `#[Operation]` →
 * `#[Tag]`s merged → `#[Response]`s appended → native `#[\Deprecated]`.
 */
final readonly class OperationBuilder
{
    /**
     * Vendor-extension key a primary-response resolver sets on its `OA\Response` to signal that the
     * status was read explicitly from the controller body (not a default), so the resource
     * convention defers to it (#240). Transient — stripped here before the response is emitted.
     */
    public const string EXPLICIT_STATUS_EXTENSION = 'laravel-openapi-explicit-status';

    public function __construct(
        private UriParameterResolver $uriResolver,
        private UriParametersExtractor $uriExtractor,
        private RequestBodyExtractor $bodyExtractor,
        private SecurityExtractor $securityExtractor,
        private ExampleFileLoader $fileLoader,
        private ResolverFaultBoundary $faultBoundary,
        private DocBlockParser $docBlockParser,
        /**
         * @var list<RefSchemaResolver>
         */
        private array $refSchemaResolvers = [],
        /**
         * @var list<QueryParameterResolver>
         */
        private array $queryParameterResolvers = [],
        /**
         * @var list<PrimaryResponseResolver>
         */
        private array $primaryResponseResolvers = [],
        /**
         * @var list<OperationConventionResolver>
         */
        private array $operationConventionResolvers = [],
    ) {}

    /** @return list<OA\SecurityScheme> */
    public function buildSecuritySchemes(): array
    {
        return $this->securityExtractor->buildSchemes();
    }

    /**
     * `requestBody` and `operationIdOverride` are internal keys stripped by the caller before
     * constructing the annotation, as they do not map onto OA\Operation properties.
     *
     * @param list<string> $defaultTags Namespace-derived tag(s); merged with attribute tags.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    public function build(ActionDescriptor $action, array $defaultTags): OperationDescriptor
    {
        $pathParams = $this->uriExtractor->extract(
            array_map(
                fn(ReflectionParameter $parameter): array
                    => [
                        $this->uriResolver->resolve(
                            $parameter,
                            $action->constraintFor($parameter->getName()),
                            $action->bindingFieldFor($parameter->getName()),
                        ),
                        $parameter,
                    ],
                $action->uriParameters,
            ),
        );
        // Dedup across resolvers by (name, in): two resolvers emitting the same parameter would
        // otherwise yield a duplicate, which is invalid OpenAPI. Resolvers run in plugin order
        // (Core first), and a later resolver wins — a plugin's richer parameter replaces an
        // earlier one of the same name. Exception: a name claimed by an explicit #[QueryParam]
        // attribute keeps its first (attribute-shaped) emission — explicit authoring beats any
        // later inference, mirroring the response-side authoring-attribute precedence (epic #5).
        $explicitQueryParameterNames = $this->explicitQueryParameterNames($action);
        $queryParamsByKey = [];

        foreach ($this->queryParameterResolvers as $queryResolver) {
            $resolved = $this->faultBoundary->isolate(
                $queryResolver::class,
                $action,
                fn(): array => $queryResolver->resolveQueryParameters($action),
            ) ?? [];

            foreach ($resolved as $param) {
                $key = $param->name . "\0" . $param->in;

                if (
                    isset($queryParamsByKey[$key])
                    && $param->in === 'query'
                    && in_array($param->name, $explicitQueryParameterNames, true)
                ) {
                    continue;
                }

                $queryParamsByKey[$key] = $param;
            }
        }

        $queryParams = array_values($queryParamsByKey);

        $security = $this->resolveSecurity($action);
        $headerParams = $this->readHeaderAttributes($action);
        $requestBody = $this->bodyExtractor->extractFromMethod($action);
        $requestBody = $this->applyRequestBodyOverride($action, $requestBody);
        $this->applyRequestExamples($action, $requestBody);

        // Consult registered primary-response resolvers, first non-null wins.
        $autoPrimaryResponse = null;

        foreach ($this->primaryResponseResolvers as $responseResolver) {
            $autoPrimaryResponse = $this->faultBoundary->isolate(
                $responseResolver::class,
                $action,
                fn(): ?OA\Response => $responseResolver->resolvePrimaryResponse($action),
            );

            if ($autoPrimaryResponse !== null) {
                break;
            }
        }

        $convention = $this->resolveConvention($action);

        $operationOverride = $this->readAttribute($action, OperationAttribute::class);
        assert($operationOverride === null || $operationOverride instanceof OperationAttribute);
        $additionalTags = $this->readTagAttributes($action);
        $additionalResponses = $this->readResponseAttributes($action);
        $deprecation = $this->readDeprecation($action);
        $externalDocs = $this->readExternalDocsAttribute($action);

        // If a `#[Response(status: 2xx, ...)]` attribute is present, it overrides the auto-derived
        // primary response — the attribute wins as both description and schema source. Any 2xx
        // status is eligible; the first one in declaration order becomes the primary override.
        // Remove it from $additionalResponses so it doesn't appear twice.
        $primaryOverride = null;
        $filteredAdditional = [];

        foreach ($additionalResponses as $additionalResponse) {
            $status = (int) (string) $additionalResponse->response;

            if ($primaryOverride === null && $status >= 200 && $status <= 299) {
                $primaryOverride = $additionalResponse;
            } else {
                $filteredAdditional[] = $additionalResponse;
            }
        }

        // A resolver may flag that it read the status explicitly from the controller body; that
        // status is ground truth the convention must not relabel (#240). Read and strip the
        // transient marker before the response can reach the document.
        $autoStatusIsExplicit = $this->takeExplicitStatusMarker($autoPrimaryResponse);

        $additionalResponses = $filteredAdditional;
        $primaryResponse = $primaryOverride
            ?? $autoPrimaryResponse
            ?? new OA\Response(['response' => '200', 'description' => 'OK']);

        // Apply the resource convention's success status, unless an explicit #[Response(2xx)]
        // already claimed the primary response or a resolver read the status from the body. This
        // layers on top of whatever body a resolver produced — a `store` returning a model keeps
        // its schema, just at 201 — but never overrides a status the author actually wrote.
        if ($primaryOverride === null && !$autoStatusIsExplicit && $convention?->successStatusCode !== null) {
            $primaryResponse = $this->applyConventionStatus($primaryResponse, $convention->successStatusCode);
        }

        // Primary 2xx first, then explicit non-2xx #[Response] attributes. Inferred error
        // responses are appended later by ErrorResponseInferenceStage, which itself skips any
        // status already declared here.
        $responses = [$primaryResponse, ...$additionalResponses];
        $this->applyResponseExamples($action, $responses);
        $this->applyResponseHeaders($action, $responses);
        $this->applyLinkAttributes($action, $primaryResponse);

        $summary = $this->resolveSummary($action, $convention);
        $description = $this->resolveDescription($action);

        if ($operationOverride !== null) {
            $baseTags = match (true) {
                // Explicit opt-in: discard controller-derived tags.
                $operationOverride->tags !== null && $operationOverride->replace => $operationOverride->tags,

                // Default: merge attribute tags with controller-derived tags.
                $operationOverride->tags !== null => $this->mergeTags(
                    $defaultTags,
                    $operationOverride->tags,
                ),
                default => $defaultTags,
            };
        } else {
            $baseTags = $defaultTags;
        }

        if ($deprecation !== null) {
            $description = $description !== null && $description !== ''
                ? $description . "\n\n**Deprecated:** " . $deprecation
                : '**Deprecated:** ' . $deprecation;
        }

        return new OperationDescriptor(
            summary: $summary ?: null,
            description: $description ?: null,
            tags: $this->mergeTags($baseTags, $additionalTags),
            parameters: [...$pathParams, ...$queryParams, ...$headerParams],
            security: $security,
            responses: $responses,
            requestBody: $requestBody,
            deprecated: $deprecation !== null,
            operationId: $operationOverride?->operationId,
            externalDocs: $externalDocs,
        );
    }

    /**
     * Names claimed by an explicit `#[QueryParam]` attribute on the action or its controller.
     * These are authored ground truth: the cross-resolver dedup must not let a later resolver's
     * inferred parameter replace them.
     *
     * @return list<string>
     */
    private function explicitQueryParameterNames(ActionDescriptor $action): array
    {
        $names = [];

        foreach ([$action->controller, $action->actionReflector] as $reflector) {
            foreach ($reflector?->getAttributes(QueryParam::class) ?? [] as $attribute) {
                $names[] = $attribute->newInstance()->name;
            }
        }

        return $names;
    }

    /**
     * Resolves security requirements for an operation.
     *
     * Precedence (first match wins):
     * 1. {@see PublicEndpoint} on method or class → `[]` (truly public)
     * 2. {@see SecurityAttribute} on method or class → each instance contributes one
     *    OR-alternative to the operation's `security` list. Method-level attributes win
     *    over class-level ones on the same target; if the method has any `#[Security]`
     *    the class-level instances are ignored.
     * 3. Middleware-derived security via {@see SecurityExtractor}.
     *
     * Returns `null` when the route is authed but no scheme is derivable, so the operation omits
     * `security` rather than emitting `[]` (which would mislabel it as public).
     *
     * @return null|list<array<string, list<string>>>
     */
    private function resolveSecurity(ActionDescriptor $descriptor): ?array
    {
        if ($this->hasAttribute($descriptor, PublicEndpoint::class)) {
            return [];
        }

        $reflectionAttributes = $descriptor->actionAttributes(SecurityAttribute::class)
            ?: $descriptor->controllerAttributes(SecurityAttribute::class);

        if ($reflectionAttributes === []) {
            return $this->securityExtractor->forRoute($descriptor->route);
        }

        $requirements = [];

        foreach ($reflectionAttributes as $reflectionAttribute) {
            $security = $reflectionAttribute->newInstance();

            foreach ($this->securityExtractor->requirementForScopes($security->scopes, $security->scheme) as $entry) {
                $requirements[] = $entry;
            }
        }

        return $requirements;
    }

    /**
     * @param class-string $attribute
     */
    private function hasAttribute(ActionDescriptor $descriptor, string $attribute): bool
    {
        return $descriptor->actionAttributes($attribute) !== []
            || $descriptor->controllerAttributes($attribute) !== [];
    }

    /**
     * Method-level entries win on name collision; declaration order is otherwise preserved.
     *
     * @return list<OA\Parameter>
     */
    private function readHeaderAttributes(ActionDescriptor $descriptor): array
    {
        /** @var array<string, HeaderAttribute> $byName */
        $byName = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(HeaderAttribute::class),
                ...$descriptor->actionAttributes(HeaderAttribute::class),
            ] as $attribute
        ) {
            $instance = $attribute->newInstance();
            $byName[$instance->name] = $instance;
        }

        return array_values(array_map($this->buildHeaderParameter(...), $byName));
    }

    /**
     * When no auto-derived body exists, builds a minimal one from the override so endpoints without
     * a Data class can still be documented.
     */
    private function applyRequestBodyOverride(
        ActionDescriptor $descriptor,
        ?OA\RequestBody $auto,
    ): ?OA\RequestBody {
        $override = $this->readAttribute($descriptor, RequestBodyAttribute::class);

        if (!$override instanceof RequestBodyAttribute) {
            return $auto;
        }

        if ($auto === null) {
            $body = new OA\RequestBody([
                'required' => $override->required ?? true,
                'content' => [
                    ($override->mediaType ?? MediaType::Json)->schema(
                        new OA\Schema(['type' => 'object']),
                    ),
                ],
            ]);

            if ($override->description !== null) {
                $body->description = $override->description;
            }

            return $body;
        }

        if ($override->description !== null) {
            $auto->description = $override->description;
        }

        if ($override->required !== null) {
            $auto->required = $override->required;
        }

        if ($override->mediaType !== null && is_array($auto->content)) {
            foreach ($auto->content as $media) {
                if ($media instanceof OA\MediaType) {
                    $media->mediaType = $override->mediaType->value;
                }
            }
        }

        return $auto;
    }

    /**
     * @param class-string $attribute
     */
    private function readAttribute(ActionDescriptor $descriptor, string $attribute): ?object
    {
        $source = $descriptor->actionAttributes($attribute)[0]
            ?? $descriptor->controllerAttributes($attribute)[0]
            ?? null;

        return $source?->newInstance();
    }

    /**
     * No-op when the method carries no {@see ExampleAttribute}s, when there is no request body,
     * or when the body has no content schema.
     *
     * @throws RuntimeException
     */
    private function applyRequestExamples(ActionDescriptor $descriptor, ?OA\RequestBody $body): void
    {
        if ($body === null) {
            return;
        }

        $content = $body->content;

        if (!is_array($content) || $content === []) {
            return;
        }

        $instances = [];

        foreach ($descriptor->actionAttributes(ExampleAttribute::class) as $attribute) {
            try {
                $instances[] = $attribute->newInstance();
            } catch (InvalidArgumentException) {
                // Malformed #[Example] attribute — skip and continue generating
            }
        }

        if ($instances === []) {
            return;
        }

        $examples = $this->collectExamples($instances);

        foreach ($content as $media) {
            if ($media instanceof OA\MediaType) {
                $media->examples = $examples;
            }
        }
    }

    /**
     * @param list<BaseExampleAttribute> $instances
     *
     * @return list<OA\Examples>
     *
     * @throws RuntimeException
     */
    private function collectExamples(array $instances): array
    {
        $out = [];

        foreach ($instances as $instance) {
            // Resolve file-based examples at generation time; inline value is used as-is.
            $value = $instance->file !== null
                ? $this->fileLoader->load($instance->file)
                : $instance->value;

            $properties = [
                'example' => $instance->name,
                // swagger-php's @OA\Examples requires `summary`. Fall back to the example name so
                // users aren't forced to repeat themselves.
                'summary' => $instance->summary ?? $instance->name,
                'value' => $value,
            ];

            if ($instance->description !== null) {
                $properties['description'] = $instance->description;
            }

            $out[] = new OA\Examples($properties);
        }

        return $out;
    }

    /** @return list<string> */
    private function readTagAttributes(ActionDescriptor $descriptor): array
    {
        $tags = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(TagAttribute::class),
                ...$descriptor->actionAttributes(TagAttribute::class),
            ] as $attribute
        ) {
            $tags[] = $attribute->newInstance()->value();
        }

        return $tags;
    }

    /**
     * Class-level {@see ResponseAttribute}s are not supported — responses are per-operation.
     *
     * @return list<OA\Response>
     */
    private function readResponseAttributes(ActionDescriptor $descriptor): array
    {
        return array_map(
            fn(ReflectionAttribute $attribute): OA\Response
                => $this->buildResponseFromAttribute(
                    $attribute->newInstance(),
                    $descriptor,
                ),
            $descriptor->actionAttributes(ResponseAttribute::class),
        );
    }

    private function buildResponseFromAttribute(ResponseAttribute $attribute, ActionDescriptor $descriptor): OA\Response
    {
        $props = [
            'response' => (string) $attribute->status,
            'description' => $attribute->description,
        ];

        $mediaType = $attribute->mediaType ?? MediaType::Json;

        // Inline schema wins over $ref when both are supplied.
        if ($attribute->schema !== null) {
            $props['content'] = [
                $mediaType->schema(SchemaFromArrayDefinition::build($attribute->schema)),
            ];
        } else {
            $schemaRef = $this->resolveRefSchema($attribute->ref, $descriptor);

            if ($schemaRef !== null) {
                $props['content'] = [
                    $mediaType->schema(new OA\Schema(['ref' => $schemaRef])),
                ];
            }
        }

        return new OA\Response($props);
    }

    /**
     * Iterates registered {@see RefSchemaResolver}s to resolve a ref, first non-null result wins.
     *
     * @param null|class-string $ref
     */
    private function resolveRefSchema(?string $ref, ActionDescriptor $descriptor): ?string
    {
        if ($ref === null) {
            return null;
        }

        foreach ($this->refSchemaResolvers as $resolver) {
            $result = $this->faultBoundary->isolate(
                $resolver::class,
                $descriptor,
                fn(): ?string => $resolver->resolveRef($ref),
            );

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function readDeprecation(ActionDescriptor $descriptor): ?string
    {
        $source = $this->firstDeprecatedAttribute($descriptor);

        if ($source !== null) {
            $instance = $source->newInstance();

            if ($instance instanceof DeprecatedAttribute) {
                return $instance->reason ?? '';
            }

            // PHP 8.4 native \Deprecated.
            return $instance->message ?? '';
        }

        // Lowest precedence: the plain `@deprecated` PHPDoc tag on the action.
        $comment = $descriptor->actionReflector?->getDocComment();

        return $comment !== false && $comment !== null
            ? $this->docBlockParser->parse($comment)->deprecation()
            : null;
    }

    /**
     * Returns the first deprecation marker on the method (preferred) or controller class.
     *
     * Both the PHP 8.4 native `\Deprecated` and the package's own `#[Deprecated]` are honoured;
     * method-level attributes always win over class-level ones.
     *
     * @return null|ReflectionAttribute<DeprecatedAttribute|NativeDeprecated>
     */
    private function firstDeprecatedAttribute(ActionDescriptor $descriptor): ?ReflectionAttribute
    {
        // Method-level wins over class-level, so check action attributes first.
        foreach ([DeprecatedAttribute::class, NativeDeprecated::class] as $class) {
            $attrs = $descriptor->actionAttributes($class);

            if ($attrs !== []) {
                return $attrs[0];
            }
        }

        foreach ([DeprecatedAttribute::class, NativeDeprecated::class] as $class) {
            $attrs = $descriptor->controllerAttributes($class);

            if ($attrs !== []) {
                return $attrs[0];
            }
        }

        return null;
    }

    private function readExternalDocsAttribute(ActionDescriptor $descriptor): ?OA\ExternalDocumentation
    {
        $attribute = $this->readAttribute($descriptor, ExternalDocsAttribute::class);

        if (!$attribute instanceof ExternalDocsAttribute) {
            return null;
        }

        $props = ['url' => $attribute->url];

        if ($attribute->description !== null) {
            $props['description'] = $attribute->description;
        }

        return new OA\ExternalDocumentation($props);
    }

    /**
     * Examples for a status without a corresponding response are dropped silently.
     *
     * @param list<OA\Response> $responses
     *
     * @throws RuntimeException
     */
    private function applyResponseExamples(ActionDescriptor $descriptor, array $responses): void
    {
        $attributes = $descriptor->actionAttributes(ResponseExampleAttribute::class);

        if ($attributes === []) {
            return;
        }

        /** @var array<string, list<ResponseExampleAttribute>> $byStatus */
        $byStatus = [];

        foreach ($attributes as $attribute) {
            try {
                $instance = $attribute->newInstance();
            } catch (InvalidArgumentException) {
                // Malformed #[ResponseExample] attribute — skip and continue generating
                continue;
            }

            $byStatus[(string) $instance->status][] = $instance;
        }

        foreach ($responses as $response) {
            $status = (string) $response->response;

            if (!isset($byStatus[$status])) {
                continue;
            }

            $content = $response->content;

            // Scaffold a media type when the response has none; declaring an example implies a body
            if (!is_array($content) || $content === []) {
                $content = [MediaType::Json->schema()];
                $response->content = $content;
            }

            $examples = $this->collectExamples($byStatus[$status]);

            foreach ($content as $media) {
                if ($media instanceof OA\MediaType) {
                    $media->examples = $examples;
                }
            }
        }
    }

    /**
     * Method-level entries win on `(status, name)` collision; headers without a matching
     * response are dropped silently.
     *
     * @param list<OA\Response> $responses
     */
    private function applyResponseHeaders(ActionDescriptor $descriptor, array $responses): void
    {
        /** @var array<string, array<string, ResponseHeaderAttribute>> $byStatus */
        $byStatus = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(ResponseHeaderAttribute::class),
                ...$descriptor->actionAttributes(ResponseHeaderAttribute::class),
            ] as $attribute
        ) {
            $instance = $attribute->newInstance();
            $byStatus[(string) $instance->status][$instance->name] = $instance;
        }

        if ($byStatus === []) {
            return;
        }

        foreach ($responses as $response) {
            $status = (string) $response->response;

            if (!isset($byStatus[$status])) {
                continue;
            }

            $existing = is_array($response->headers) ? $response->headers : [];

            foreach ($byStatus[$status] as $headerAttribute) {
                $existing[] = $this->buildResponseHeader($headerAttribute);
            }

            $response->headers = $existing;
        }
    }

    private function buildResponseHeader(ResponseHeaderAttribute $header): OA\Header
    {
        $schemaProps = ['type' => $header->type];

        if ($header->format !== null) {
            $schemaProps['format'] = $header->format;
        }

        if ($header->example !== null) {
            $schemaProps['example'] = $header->example;
        }

        $props = [
            'header' => $header->name,
            'schema' => new OA\Schema($schemaProps),
        ];

        if ($header->description !== null) {
            $props['description'] = $header->description;
        }

        if ($header->required !== null) {
            $props['required'] = $header->required;
        }

        if ($header->deprecated !== null) {
            $props['deprecated'] = $header->deprecated;
        }

        return new OA\Header($props);
    }

    /**
     * Method-level only — links are per-operation, not per-controller.
     */
    private function applyLinkAttributes(ActionDescriptor $descriptor, OA\Response $primaryResponse): void
    {
        $attrs = $descriptor->actionAttributes(LinkAttribute::class);

        if ($attrs === []) {
            return;
        }

        $links = is_array($primaryResponse->links) ? $primaryResponse->links : [];

        foreach ($attrs as $attribute) {
            $instance = $attribute->newInstance();
            assert($instance instanceof LinkAttribute);

            $props = ['link' => $instance->name];

            if ($instance->operationId !== null) {
                $props['operationId'] = $instance->operationId;
            }

            if ($instance->operationRef !== null) {
                $props['operationRef'] = $instance->operationRef;
            }

            if ($instance->parameters !== []) {
                $props['parameters'] = $instance->parameters;
            }

            if ($instance->description !== null) {
                $props['description'] = $instance->description;
            }

            $links[] = new OA\Link($props);
        }

        $primaryResponse->links = $links;
    }

    /**
     * Consults registered operation-convention resolvers, first non-null wins.
     */
    private function resolveConvention(ActionDescriptor $descriptor): ?OperationConvention
    {
        foreach ($this->operationConventionResolvers as $conventionResolver) {
            $convention = $this->faultBoundary->isolate(
                $conventionResolver::class,
                $descriptor,
                fn(): ?OperationConvention => $conventionResolver->resolve($descriptor),
            );

            if ($convention !== null) {
                return $convention;
            }
        }

        return null;
    }

    /**
     * Reads and removes the {@see self::EXPLICIT_STATUS_EXTENSION} marker a primary-response
     * resolver may set to claim it read the status from the controller body. The marker is
     * transient and must never reach the serialized document, so it is always cleared.
     */
    private function takeExplicitStatusMarker(?OA\Response $response): bool
    {
        if ($response === null || is_undefined($response->x) || !is_array($response->x)) {
            return false;
        }

        $explicit = ($response->x[self::EXPLICIT_STATUS_EXTENSION] ?? null) === true;

        unset($response->x[self::EXPLICIT_STATUS_EXTENSION]);

        if ($response->x === []) {
            $response->x = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (swagger-php clears via the UNDEFINED sentinel)
        }

        return $explicit;
    }

    /**
     * Returns the primary response carrying the convention-derived status code and reason phrase.
     * A 204 carries no body by definition, so any resolved content is discarded in favour of a
     * fresh body-less response.
     */
    private function applyConventionStatus(OA\Response $response, int $statusCode): OA\Response
    {
        if ($statusCode === 204) {
            return new OA\Response(['response' => '204', 'description' => 'No Content']);
        }

        $response->response = (string) $statusCode;
        $response->description = $statusCode === 201 ? 'Created' : 'OK';

        return $response;
    }

    /**
     * Resolves the operation summary across attribute scopes, the docblock, and the convention.
     *
     * Precedence:
     *   1. action-level `#[Summary]`
     *   2. action-level `#[Operation(summary: …)]`
     *   3. action docblock
     *   4. class-level `#[Summary]`
     *   5. class-level `#[Operation(summary: …)]`
     *   6. convention-derived default summary
     *
     * The "action" is the controller method, the `__invoke` method of a single-action controller,
     * or a route closure — all reached uniformly via {@see ActionDescriptor::$actionReflector}.
     */
    private function resolveSummary(ActionDescriptor $descriptor, ?OperationConvention $convention): ?string
    {
        $methodAttr = $this->readScopedSummary(
            $descriptor->actionAttributes(SummaryAttribute::class),
            $descriptor->actionAttributes(OperationAttribute::class),
        );
        $classAttr = $this->readScopedSummary(
            $descriptor->controllerAttributes(SummaryAttribute::class),
            $descriptor->controllerAttributes(OperationAttribute::class),
        );

        return $methodAttr ?? $descriptor->summary ?? $classAttr ?? $convention?->summary;
    }

    /**
     * @param list<ReflectionAttribute<SummaryAttribute>>   $summaryAttributes
     * @param list<ReflectionAttribute<OperationAttribute>> $operationAttributes
     */
    private function readScopedSummary(array $summaryAttributes, array $operationAttributes): ?string
    {
        $summary = $summaryAttributes[0] ?? null;

        if ($summary !== null) {
            return $summary->newInstance()->value;
        }

        return ($operationAttributes[0] ?? null)?->newInstance()->summary;
    }

    /**
     * Resolves the operation description. Same precedence as {@see resolveSummary()}.
     */
    private function resolveDescription(ActionDescriptor $descriptor): ?string
    {
        $methodAttr = $this->readScopedDescription(
            $descriptor->actionAttributes(DescriptionAttribute::class),
            $descriptor->actionAttributes(OperationAttribute::class),
        );
        $classAttr = $this->readScopedDescription(
            $descriptor->controllerAttributes(DescriptionAttribute::class),
            $descriptor->controllerAttributes(OperationAttribute::class),
        );

        return $methodAttr ?? $descriptor->description ?? $classAttr;
    }

    /**
     * @param list<ReflectionAttribute<DescriptionAttribute>> $descriptionAttributes
     * @param list<ReflectionAttribute<OperationAttribute>>   $operationAttributes
     */
    private function readScopedDescription(array $descriptionAttributes, array $operationAttributes): ?string
    {
        $description = $descriptionAttributes[0] ?? null;

        if ($description !== null) {
            return $description->newInstance()->value;
        }

        return ($operationAttributes[0] ?? null)?->newInstance()->description;
    }

    /**
     * @param list<string> $base
     * @param list<string> $additional
     *
     * @return list<string>
     */
    private function mergeTags(array $base, array $additional): array
    {
        return array_values(
            array_filter(
                array_unique(array_merge($base, $additional)),
                static fn(string $tag): bool => $tag !== '',
            ),
        );
    }

    private function buildHeaderParameter(HeaderAttribute $header): OA\Parameter
    {
        $schemaProps = ['type' => $header->type];

        if ($header->format !== null) {
            $schemaProps['format'] = $header->format;
        }

        if ($header->example !== null) {
            $schemaProps['example'] = $header->example;
        }

        $props = [
            'name' => $header->name,
            'in' => 'header',
            'required' => $header->required,
            'schema' => new OA\Schema($schemaProps),
        ];

        if ($header->description !== null) {
            $props['description'] = $header->description;
        }

        if ($header->deprecated !== null) {
            $props['deprecated'] = $header->deprecated;
        }

        return new OA\Parameter($props);
    }
}
