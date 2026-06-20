<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Deprecated as NativeDeprecated;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Attributes\BaseExample as BaseExampleAttribute;
use Radiergummi\OpenApi\Attributes\CookieParam as CookieParamAttribute;
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
use Radiergummi\OpenApi\Attributes\ResponseExampleFile as ResponseExampleFileAttribute;
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
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;
use Radiergummi\OpenApi\Support\Provenance\ResolvedConvention;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;
use Radiergummi\OpenApi\Support\Routing\UriParameterDescriptor;
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
use function class_basename;
use function implode;
use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Builds the property array dispatched onto OA\Get/OA\Post/etc. for one route action.
 *
 * @internal
 */
final readonly class OperationBuilder
{
    /**
     * Transient vendor-extension key: signals the resolver read the status from the body, so the
     * convention must not overwrite it. Stripped before the response reaches the document.
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
     * @param list<string> $defaultTags Namespace-derived tag(s); merged with attribute tags.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    public function build(ActionDescriptor $action, array $defaultTags): OperationDescriptor
    {
        $pathParams = $this->uriExtractor->extract(
            $this->resolveUriParameterPairs($action),
        );
        // Dedup by (name, in) across resolvers; later resolver wins, except names claimed by an
        // explicit #[QueryParam] attribute, which authoring locks.
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
        $cookieParams = $this->readCookieAttributes($action);
        $requestBody = $this->bodyExtractor->extractFromMethod($action);
        $requestBody = $this->applyRequestBodyOverride($action, $requestBody);
        $this->applyRequestExamples($action, $requestBody);

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

        $resolvedConvention = $this->resolveConvention($action);
        $convention = $resolvedConvention?->convention;

        $operationOverride = $this->readAttribute($action, OperationAttribute::class);
        assert($operationOverride === null || $operationOverride instanceof OperationAttribute);
        $additionalTags = $this->readTagAttributes($action);
        $additionalResponses = $this->readResponseAttributes($action);
        $deprecation = $this->readDeprecation($action);
        $externalDocs = $this->readExternalDocsAttribute($action);

        // First 2xx #[Response] in declaration order overrides the auto-derived primary; remove it
        // from $additionalResponses to avoid duplication.
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

        // Strip the transient marker before the response reaches the document.
        $autoStatusIsExplicit = $this->takeExplicitStatusMarker($autoPrimaryResponse);

        $additionalResponses = $filteredAdditional;
        $primaryResponse = $primaryOverride
            ?? $autoPrimaryResponse
            ?? new OA\Response(['response' => '200', 'description' => 'OK']);

        // Apply convention status unless an explicit #[Response(2xx)] or body-scanned status claimed it.
        if ($primaryOverride === null && !$autoStatusIsExplicit && $convention?->successStatusCode !== null) {
            $primaryResponse = $this->applyConventionStatus($primaryResponse, $convention->successStatusCode);
        }

        $statusProvenance = $this->statusProvenance(
            (string) $primaryResponse->response,
            $primaryOverride !== null,
            $autoStatusIsExplicit,
            $resolvedConvention,
            $action,
        );

        // Primary 2xx first; ErrorResponseInferenceStage appends errors later, skipping statuses already declared.
        $responses = [$primaryResponse, ...$additionalResponses];
        $this->applyResponseExamples($action, $responses);
        $this->applyResponseExampleFiles($action, $responses, $primaryResponse);
        $this->applyResponseHeaders($action, $responses);
        $this->applyLinkAttributes($action, $primaryResponse);

        [$summary, $summaryProvenance] = $this->resolveSummary($action, $resolvedConvention);
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

        $tags = $this->mergeTags($baseTags, $additionalTags);

        $provenance = array_values(array_filter([
            $summaryProvenance,
            $statusProvenance,
            $this->tagProvenance($tags, $operationOverride, $additionalTags),
        ]));

        return new OperationDescriptor(
            summary: $summary ?: null,
            description: $description ?: null,
            tags: $tags,
            parameters: [...$pathParams, ...$queryParams, ...$headerParams, ...$cookieParams],
            security: $security,
            responses: $responses,
            requestBody: $requestBody,
            deprecated: $deprecation !== null,
            operationId: $operationOverride?->operationId,
            externalDocs: $externalDocs,
            provenance: $provenance,
        );
    }

    /**
     * Builds the descriptor/reflection pairs for the extractor: signature-derived parameters first,
     * then any URI placeholder absent from the signature (invokable controllers, `Request`-only
     * actions, the parent of a scoped/nested binding) synthesized as a string path parameter.
     *
     * @return list<array{UriParameterDescriptor, ?ReflectionParameter}>
     *
     * @throws UnsupportedException
     */
    private function resolveUriParameterPairs(ActionDescriptor $action): array
    {
        $pairs = [];
        $declaredNames = [];

        foreach ($action->uriParameters as $parameter) {
            $name = $parameter->getName();
            $declaredNames[$name] = true;
            $pairs[] = [
                $this->uriResolver->resolve(
                    $parameter,
                    $action->constraintFor($name),
                    $action->bindingFieldFor($name),
                ),
                $parameter,
            ];
        }

        foreach ($action->uriPlaceholders() as [$name, $optional]) {
            if (isset($declaredNames[$name])) {
                continue;
            }

            $declaredNames[$name] = true;
            $pairs[] = [
                $this->uriResolver->resolveUnsignatured(
                    $name,
                    $action->constraintFor($name),
                    $action->bindingFieldFor($name),
                    $optional,
                ),
                null,
            ];
        }

        return $pairs;
    }

    /** @return list<string> */
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
     * Precedence: `#[PublicEndpoint]` → `#[Security]` (method shadows class) → middleware-derived.
     * Returns `null` when authed but no scheme is derivable; omitting `security` is different from
     * `[]`, which would mislabel the operation as public.
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
     * Method-level entries win on name collision.
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
     * Method-level entries win on name collision.
     *
     * @return list<OA\Parameter>
     */
    private function readCookieAttributes(ActionDescriptor $descriptor): array
    {
        /** @var array<string, CookieParamAttribute> $byName */
        $byName = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(CookieParamAttribute::class),
                ...$descriptor->actionAttributes(CookieParamAttribute::class),
            ] as $attribute
        ) {
            $instance = $attribute->newInstance();
            $byName[$instance->name] = $instance;
        }

        return array_values(array_map($this->buildCookieParameter(...), $byName));
    }

    /**
     * When no auto-derived body exists, builds a minimal one from the override.
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

    /** @throws RuntimeException */
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
                // Malformed #[Example] attribute; skip and continue generating
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
            // Resolve file-based examples at generation time.
            $value = $instance->file !== null
                ? $this->fileLoader->load($instance->file)
                : $instance->value;

            $properties = [
                'example' => $instance->name,
                // OA\Examples requires `summary`; fall back to the name.
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

    private function resolveConvention(ActionDescriptor $descriptor): ?ResolvedConvention
    {
        foreach ($this->operationConventionResolvers as $conventionResolver) {
            $convention = $this->faultBoundary->isolate(
                $conventionResolver::class,
                $descriptor,
                fn(): ?OperationConvention => $conventionResolver->resolve($descriptor),
            );

            if ($convention !== null) {
                // Capture which resolver decided, so provenance names it rather than a literal.
                return new ResolvedConvention($convention, $conventionResolver::class);
            }
        }

        return null;
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
     * Class-level `#[Response]` attributes are not supported; responses are per-operation.
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

    /** @param null|class-string $ref */
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

            // PHP 8.4 native \Deprecated attribute.
            return $instance->message ?? '';
        }

        // Fall back to the plain `@deprecated` PHPDoc tag.
        $comment = $descriptor->actionReflector?->getDocComment();

        return $comment !== false && $comment !== null
            ? $this->docBlockParser->parse($comment)->deprecation()
            : null;
    }

    /** @return null|ReflectionAttribute<DeprecatedAttribute|NativeDeprecated> */
    private function firstDeprecatedAttribute(ActionDescriptor $descriptor): ?ReflectionAttribute
    {
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
     * Reads and strips the transient {@see self::EXPLICIT_STATUS_EXTENSION} vendor extension.
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

    /** A 204 discards any resolved body. */
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
     * Examples for a status without a matching response are dropped silently.
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
                // Malformed #[ResponseExample] attribute; skip and continue generating
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

            // An example implies a body; scaffold a media type when the response has none.
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
     * Attaches `#[ResponseExampleFile]` payloads as the singular media-type `example` on a response.
     *
     * `status: null` targets the already-resolved primary response. Files for a status with no
     * matching response are dropped silently; so are files for a conventionally bodyless status
     * (204/205/304) that has no content, since scaffolding a JSON body there is invalid OpenAPI.
     * When a media type already carries named `examples` (e.g. from `#[ResponseExample]`), the
     * singular `example` is left unset: the two are mutually exclusive on one media type.
     *
     * @param list<OA\Response> $responses
     *
     * @throws RuntimeException When a referenced file is missing or not valid JSON.
     */
    private function applyResponseExampleFiles(
        ActionDescriptor $descriptor,
        array $responses,
        OA\Response $primaryResponse,
    ): void {
        $attributes = $descriptor->actionAttributes(ResponseExampleFileAttribute::class);

        if ($attributes === []) {
            return;
        }

        /** @var array<string, OA\Response> $byStatus */
        $byStatus = [];

        foreach ($responses as $response) {
            $byStatus[(string) $response->response] = $response;
        }

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            $response = $instance->status !== null
                ? ($byStatus[(string) $instance->status] ?? null)
                : $primaryResponse;

            if ($response === null) {
                continue;
            }

            $content = $response->content;

            if (!is_array($content) || $content === []) {
                // A bodyless status must not gain a JSON body just to carry an example. The set is
                // inlined deliberately: the canonical list is a private Lint-layer detail, and
                // Support must not depend on Lint.
                if (in_array((int) $response->response, [204, 205, 304], true)) {
                    continue;
                }

                $content = [MediaType::Json->schema()];
                $response->content = $content;
            }

            $example = $this->fileLoader->load($instance->file);

            foreach ($content as $media) {
                // Named examples and a singular example are mutually exclusive on one media type.
                if ($media instanceof OA\MediaType && !is_array($media->examples)) {
                    $media->example = $example;
                }
            }
        }
    }

    /**
     * Method-level entries win on `(status, name)` collision; unmatched headers are dropped silently.
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

    /** Links are per-operation, not per-controller. */
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
     * Precedence: action `#[Summary]` → action `#[Operation(summary)]` → docblock →
     * class `#[Summary]` → class `#[Operation(summary)]` → convention default.
     *
     * @return array{0: ?string, 1: ?FieldProvenance}
     */
    private function resolveSummary(ActionDescriptor $descriptor, ?ResolvedConvention $convention): array
    {
        $methodAttr = $this->readScopedSummary(
            $descriptor->actionAttributes(SummaryAttribute::class),
            $descriptor->actionAttributes(OperationAttribute::class),
        );
        $classAttr = $this->readScopedSummary(
            $descriptor->controllerAttributes(SummaryAttribute::class),
            $descriptor->controllerAttributes(OperationAttribute::class),
        );

        $conventionSummary = $convention?->convention->summary;
        $summary = $methodAttr ?? $descriptor->summary ?? $classAttr ?? $conventionSummary;

        if ($summary === null) {
            return [null, null];
        }

        // The winning branch decides the source; the convention is the only candidate worth
        // showing as superseded, since it is what surprises authors who expected it to apply.
        [$source, $reason] = match (true) {
            $methodAttr !== null => ['#[Summary] (method)', 'author override'],
            $descriptor->summary !== null => ['docblock', 'method docblock summary'],
            $classAttr !== null => ['#[Summary] (class)', 'class-level override'],
            default => [
                $convention?->resolver !== null ? class_basename($convention->resolver) : 'convention',
                $this->conventionReason($descriptor),
            ],
        };

        $superseded = [];

        if ($summary !== $conventionSummary && $conventionSummary !== null) {
            $superseded[] = "convention '{$conventionSummary}'";
        }

        return [$summary, new FieldProvenance('summary', $summary, $source, $reason, $superseded)];
    }

    /**
     * Provenance for the resolved success status code: explicit `#[Response(2xx)]`, a body-scanned
     * explicit status, the convention, or the `200` fallback.
     */
    private function statusProvenance(
        string $status,
        bool $hasResponseOverride,
        bool $bodyScannedExplicit,
        ?ResolvedConvention $convention,
        ActionDescriptor $descriptor,
    ): FieldProvenance {
        [$source, $reason, $superseded] = match (true) {
            $hasResponseOverride => ['#[Response] (method)', 'author override', []],
            $bodyScannedExplicit => ['response body', 'explicit status in handler body', []],
            $convention?->convention->successStatusCode !== null => [
                class_basename($convention->resolver),
                $this->conventionReason($descriptor),
                [],
            ],
            default => ['default', 'no convention matched', []],
        };

        return new FieldProvenance('status', $status, $source, $reason, $superseded);
    }

    /**
     * Provenance for the merged tag list. Names the attribute and whether it replaced or merged
     * with the controller-derived tag; otherwise reports the controller-derived source.
     *
     * @param list<string> $tags
     * @param list<string> $additionalTags
     */
    private function tagProvenance(
        array $tags,
        ?OperationAttribute $operationOverride,
        array $additionalTags,
    ): ?FieldProvenance {
        if ($tags === []) {
            return null;
        }

        $value = implode(', ', $tags);

        if ($operationOverride?->tags !== null && $operationOverride->replace) {
            return new FieldProvenance('tags', $value, '#[Operation] (replace)', 'attribute tags replace controller-derived');
        }

        if ($operationOverride?->tags !== null) {
            return new FieldProvenance('tags', $value, '#[Operation] (merge)', 'attribute tags merged with controller-derived');
        }

        if ($additionalTags !== []) {
            return new FieldProvenance('tags', $value, '#[Tag]', 'attribute tags merged with controller-derived');
        }

        return new FieldProvenance('tags', $value, 'controller-derived', 'controller short name');
    }

    /**
     * Reconstructs the convention's matched signal (`store → POST`) from Tier-0 reflection
     * already on hand: the action method name and the HTTP verb being emitted.
     */
    private function conventionReason(ActionDescriptor $descriptor): string
    {
        $method = $descriptor->method?->getName() ?? '?';
        $verb = $descriptor->httpMethod?->forDisplay() ?? '?';

        return "{$method} → {$verb}";
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

    /** Same precedence as {@see resolveSummary()}. */
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

    private function buildCookieParameter(CookieParamAttribute $cookie): OA\Parameter
    {
        $schema = $cookie->descriptor()->toSchema();

        // Cookies are always string-valued on the wire; default the schema type when unset.
        if ($schema->type === Generator::UNDEFINED) {
            $schema->type = 'string';
        }

        $props = [
            'name' => $cookie->name,
            'in' => 'cookie',
            'required' => $cookie->required,
            'schema' => $schema,
        ];

        if ($cookie->deprecated) {
            $props['deprecated'] = true;
        }

        return new OA\Parameter($props);
    }
}
