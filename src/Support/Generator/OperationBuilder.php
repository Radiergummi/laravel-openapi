<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Deprecated as NativeDeprecated;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\Deprecated as DeprecatedAttribute;
use Radiergummi\OpenApi\Attributes\Description as DescriptionAttribute;
use Radiergummi\OpenApi\Attributes\ExternalDocs as ExternalDocsAttribute;
use Radiergummi\OpenApi\Attributes\Operation as OperationAttribute;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\RequestBody as RequestBodyAttribute;
use Radiergummi\OpenApi\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Attributes\ResponseResource as ResponseResourceAttribute;
use Radiergummi\OpenApi\Attributes\Security as SecurityAttribute;
use Radiergummi\OpenApi\Attributes\Summary as SummaryAttribute;
use Radiergummi\OpenApi\Attributes\Tag as TagAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Registry\OperationConvention;
use Radiergummi\OpenApi\Contracts\Registry\OperationConventionResolver;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Attributes\QueryParamReader;
use Radiergummi\OpenApi\Support\Extraction\RequestBodyExtractor;
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;
use Radiergummi\OpenApi\Support\Extraction\UriParametersExtractor;
use Radiergummi\OpenApi\Support\Generator\Appliers\ExampleApplier;
use Radiergummi\OpenApi\Support\Generator\Appliers\LinkApplier;
use Radiergummi\OpenApi\Support\Generator\Appliers\RequestParameterApplier;
use Radiergummi\OpenApi\Support\Generator\Appliers\ResponseHeaderApplier;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Provenance\FieldCandidate;
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;
use Radiergummi\OpenApi\Support\Provenance\ResolvedConvention;
use Radiergummi\OpenApi\Support\Provenance\ResolvedField;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Support\Routing\UriParameterDescriptor;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use ReflectionAttribute;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function class_basename;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;

/**
 * Builds the property array dispatched onto OA\Get/OA\Post/etc. for one route action.
 *
 * @internal
 */
final readonly class OperationBuilder
{
    private readonly RequestParameterApplier $requestParameterApplier;

    private readonly ResponseHeaderApplier $responseHeaderApplier;

    private readonly ExampleApplier $exampleApplier;

    private readonly LinkApplier $linkApplier;

    public function __construct(
        private UriParameterResolver $uriResolver,
        private UriParametersExtractor $uriExtractor,
        private RequestBodyExtractor $bodyExtractor,
        private SecurityExtractor $securityExtractor,
        private ExampleFileLoader $fileLoader,
        private ResolverFaultBoundary $faultBoundary,
        private DocBlockParser $docBlockParser,
        private RouteMiddlewareGatherer $middlewareGatherer,
        private FindingsCollector $findings,
        private QueryParamReader $queryParamReader = new QueryParamReader(),
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
    ) {
        // The appliers own one attribute-family's OA\* construction each; this builder orchestrates
        // them and keeps field precedence. They are stateless, so building them here (rather than
        // autowiring) keeps the wiring local without a shared-cache concern.
        $this->requestParameterApplier = new RequestParameterApplier();
        $this->responseHeaderApplier = new ResponseHeaderApplier($this->middlewareGatherer);
        $this->exampleApplier = new ExampleApplier($this->fileLoader);
        $this->linkApplier = new LinkApplier();
    }

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
            $action->paramDescriptions,
        );
        // Dedup by (name, in) across resolvers; later resolver wins, except names claimed by an
        // explicit #[QueryParam] attribute, which authoring locks.
        $explicitQueryParameterNames = $this->explicitQueryParameterNames($action);
        $requestParamsByKey = [];

        foreach ($this->queryParameterResolvers as $queryResolver) {
            $resolved = $this->faultBoundary->isolate(
                $queryResolver::class,
                $action,
                fn(): array => $queryResolver->resolveQueryParameters($action),
            ) ?? [];

            foreach ($resolved as $param) {
                $key = $param->name . "\0" . $param->in;

                if (
                    isset($requestParamsByKey[$key])
                    && $param->in === 'query'
                    && in_array($param->name, $explicitQueryParameterNames, true)
                ) {
                    continue;
                }

                $requestParamsByKey[$key] = $param;
            }
        }

        // Split the resolver output by location: query keeps its own group, while inferred cookie
        // and header reads are held in name-keyed maps so the attribute params can fold in last.
        $queryParams = [];
        $headerParamsByName = [];
        $cookieParamsByName = [];

        foreach ($requestParamsByKey as $param) {
            if ($param->in === 'header') {
                // Header field names are case-insensitive (RFC 9110 §5.1), so case-differing reads
                // are the same header; the stored param keeps its original casing on the wire.
                $headerParamsByName[strtolower($param->name)] = $param;
            } elseif ($param->in === 'cookie') {
                $cookieParamsByName[$param->name] = $param;
            } else {
                $queryParams[] = $param;
            }
        }

        // Lowest-precedence query-param descriptions: fill only empties left by every resolver, so
        // a #[QueryParam] or inline-validate() description always wins. Plugin-agnostic, so it lives
        // here rather than in any one resolver. Resolvers carry the description on the parameter's
        // schema, so the fallback is read and written there too.
        foreach ($queryParams as $param) {
            if ($param->in !== 'query' || !$param->schema instanceof OA\Schema) {
                continue;
            }

            if (is_defined($param->schema->description) && $param->schema->description !== '') {
                continue;
            }

            $fallback = $action->paramDescriptions[$param->name] ?? null;

            if ($fallback !== null) {
                $param->schema->description = $fallback;
            }
        }

        $security = $this->resolveSecurity($action);

        // Fold the attribute header/cookie params in last, overwriting an inferred read of the same
        // name so #[Header]/#[CookieParam] win — symmetric to the #[QueryParam] lock above.
        foreach ($this->requestParameterApplier->headerParameters($action) as $param) {
            // Same case-insensitive key as the inferred fold above, so an attribute overwrites a
            // case-differing inferred read of the same header (authoring wins, its casing wins).
            $headerParamsByName[strtolower($param->name)] = $param;
        }

        foreach ($this->requestParameterApplier->cookieParameters($action) as $param) {
            $cookieParamsByName[$param->name] = $param;
        }

        $headerParams = array_values($headerParamsByName);
        $cookieParams = array_values($cookieParamsByName);
        $requestBody = $this->bodyExtractor->extractFromMethod($action);
        $requestBody = $this->applyRequestBodyOverride($action, $requestBody);
        $this->exampleApplier->applyRequestExamples($action, $requestBody);

        $autoPrimaryResult = null;

        foreach ($this->primaryResponseResolvers as $responseResolver) {
            $autoPrimaryResult = $this->faultBoundary->isolate(
                $responseResolver::class,
                $action,
                fn(): ?PrimaryResponse => $responseResolver->resolvePrimaryResponse($action),
            );

            if ($autoPrimaryResult !== null) {
                break;
            }
        }

        $autoPrimaryResponse = $autoPrimaryResult?->response;
        $autoStatusIsExplicit = $autoPrimaryResult->statusIsExplicit ?? false;

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

        $additionalResponses = $filteredAdditional;

        $this->emitMissingResponseSchemaFinding(
            $action,
            responseProduced: $primaryOverride !== null || $autoPrimaryResponse !== null,
        );

        $primaryResponse = $primaryOverride
            ?? $autoPrimaryResponse
            ?? new OA\Response(['response' => '200', 'description' => 'OK']);

        // A 204 forbids a body; a content-bearing body-scanned response is stronger evidence of the
        // real status, so it keeps its 200 + schema instead of being discarded for a bogus 204.
        $conventionSuppressedByBody = $convention?->successStatusCode === 204
            && $autoPrimaryResponse !== null
            && is_array($autoPrimaryResponse->content)
            && $autoPrimaryResponse->content !== [];

        // Apply convention status unless an explicit #[Response(2xx)] or body-scanned status claimed
        // it, or a 204 convention is yielding to a content-bearing body.
        if (
            $primaryOverride === null
            && !$autoStatusIsExplicit
            && $convention?->successStatusCode !== null
            && !$conventionSuppressedByBody
        ) {
            $primaryResponse = $this->applyConventionStatus($primaryResponse, $convention->successStatusCode);
        }

        $statusProvenance = $this->statusProvenance(
            (string) $primaryResponse->response,
            $primaryOverride !== null,
            $autoStatusIsExplicit,
            $conventionSuppressedByBody,
            $resolvedConvention,
            $action,
        );

        // Primary 2xx first; ErrorResponseInferenceStage appends errors later, skipping statuses already declared.
        $responses = [$primaryResponse, ...$additionalResponses];
        $this->exampleApplier->applyResponseExamples($action, $responses);
        $this->exampleApplier->applyResponseExampleFiles($action, $responses, $primaryResponse);
        $this->responseHeaderApplier->applyAuthored($action, $responses);
        $this->responseHeaderApplier->applyConventional($action, $responses, $primaryResponse);
        $this->linkApplier->apply($action, $primaryResponse);

        [$summary, $summaryProvenance] = $this->resolveSummary($action, $resolvedConvention);
        [$description, $descriptionProvenance] = $this->resolveDescription($action);

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
        $operationId = $operationOverride?->operationId;

        $provenance = array_values(array_filter([
            $summaryProvenance,
            $descriptionProvenance,
            $statusProvenance,
            $this->tagProvenance($tags, $operationOverride, $additionalTags),
            $this->deprecatedProvenance($action, $deprecation),
            $this->operationIdProvenance($operationId),
            $this->externalDocsProvenance($externalDocs),
            $this->securityProvenance($action, $security),
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
            operationId: $operationId,
            externalDocs: $externalDocs,
            provenance: $provenance,
        );
    }

    /**
     * Reports an action that yields no response schema: no resolver produced a response, no
     * `#[Response]`/`#[ResponseResource]` is present, and the return type is absent or one of
     * `mixed`/`void`/`never`. Emitted here, where the response-resolution result is known, rather
     * than re-derived by a lint rule (`operation.return-type-missing`).
     */
    private function emitMissingResponseSchemaFinding(ActionDescriptor $action, bool $responseProduced): void
    {
        // A produced response, an intended webhook, a response attribute, or a usable return type
        // each means a schema could be inferred (or the operation is not response-schema-bearing).
        if ($responseProduced || OverrideMatcher::webhookKeyFor($action) !== null) {
            return;
        }

        if ($this->hasResponseAttribute($action) || $this->hasUsableReturnType($action)) {
            return;
        }

        $controllerName = $action->controller?->getName();

        $this->findings->emit(new Finding(
            ruleId: 'operation.return-type-missing',
            severity: Severity::Inconsistent,
            message: sprintf(
                '%s::%s() %s, so no response schema can be inferred',
                $action->controller?->getShortName() ?? '(unknown)',
                $action->method?->getName() ?? '(unknown)',
                $this->missingReturnReason($action),
            ),
            location: FindingLocation::fromDescriptor($action),
            fixHint: 'Add a return type to the action, or annotate it with #[Response] / #[ResponseResource].',
            context: $controllerName !== null ? [Finding::CONTEXT_SOURCE_CLASS => $controllerName] : [],
        ));
    }

    private function hasResponseAttribute(ActionDescriptor $descriptor): bool
    {
        return $descriptor->attributeInstances(ResponseAttribute::class) !== []
            || $descriptor->attributeInstances(ResponseResourceAttribute::class) !== [];
    }

    /**
     * A return type is "usable" when declared and not `mixed`, `void`, or `never`.
     */
    private function hasUsableReturnType(ActionDescriptor $descriptor): bool
    {
        $returnType = $descriptor->actionReflector?->getReturnType();

        if ($returnType === null) {
            return false;
        }

        // Union/intersection types are still a declared shape.
        if (!$returnType instanceof ReflectionNamedType) {
            return true;
        }

        return !in_array($returnType->getName(), ['mixed', 'void', 'never'], true);
    }

    /**
     * Distinguishes an absent return type from an unusable one for the finding's reason.
     */
    private function missingReturnReason(ActionDescriptor $descriptor): string
    {
        $returnType = $descriptor->actionReflector?->getReturnType();

        if ($returnType instanceof ReflectionNamedType
            && in_array($returnType->getName(), ['mixed', 'void', 'never'], true)) {
            return "declares a `{$returnType->getName()}` return type";
        }

        return 'has no return type or response attribute';
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
        return array_map(
            static fn(QueryParam $parameter): string => $parameter->name,
            $this->queryParamReader->read($action->controller, $action->actionReflector),
        );
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

        $mediaTypes = $this->declaredMediaTypes($override->mediaTypes);

        if ($auto === null) {
            // No resolved schema exists, so build a fresh object-fallback schema per entry:
            // swagger-php can set parent back-references during serialization, so one instance
            // must not be shared across media types.
            $content = $mediaTypes !== null
                ? array_map(
                    fn(MediaType $type): OA\MediaType => $type->schema(new OA\Schema(['type' => 'object'])),
                    $mediaTypes,
                )
                : [($override->mediaType ?? MediaType::Json)->schema(new OA\Schema(['type' => 'object']))];

            $body = new OA\RequestBody([
                'required' => $override->required ?? true,
                'content' => $content,
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

        if ($mediaTypes !== null && is_array($auto->content)) {
            $resolved = $auto->content[0] ?? null;

            // Fan out the already-resolved auto-body schema across every declared type, sharing
            // the single resolved schema reference (e.g. a $ref wrapper) across entries.
            if ($resolved instanceof OA\MediaType && $resolved->schema instanceof OA\Schema) {
                $schema = $resolved->schema;
                $auto->content = array_map(
                    fn(MediaType $type): OA\MediaType => $type->schema($schema),
                    $mediaTypes,
                );
            }
        } elseif ($override->mediaType !== null && is_array($auto->content)) {
            foreach ($auto->content as $media) {
                if ($media instanceof OA\MediaType) {
                    $media->mediaType = $override->mediaType->value;
                }
            }
        }

        return $auto;
    }

    /**
     * Normalises a declared `mediaTypes` list, treating null and empty as "not declared".
     *
     * @param null|list<MediaType> $mediaTypes
     *
     * @return null|non-empty-list<MediaType>
     */
    private function declaredMediaTypes(?array $mediaTypes): ?array
    {
        return $mediaTypes === null || $mediaTypes === [] ? null : $mediaTypes;
    }

    /**
     * Builds the content entries for a resolved schema, one per declared media type or a single
     * entry under the default when none are declared.
     *
     * @param null|list<MediaType> $mediaTypes
     *
     * @return non-empty-list<OA\MediaType>
     */
    private function contentEntriesFor(OA\Schema $schema, ?array $mediaTypes, MediaType $default): array
    {
        $declared = $this->declaredMediaTypes($mediaTypes);

        if ($declared === null) {
            return [$default->schema($schema)];
        }

        return array_map(
            fn(MediaType $type): OA\MediaType => $type->schema($schema),
            $declared,
        );
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
            $props['content'] = $this->contentEntriesFor(
                SchemaFromArrayDefinition::build($attribute->schema),
                $attribute->mediaTypes,
                $mediaType,
            );
        } else {
            $schemaRef = $this->resolveRefSchema($attribute->ref, $descriptor);

            if ($schemaRef !== null) {
                $props['content'] = $this->contentEntriesFor(
                    new OA\Schema(['ref' => $schemaRef]),
                    $attribute->mediaTypes,
                    $mediaType,
                );
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
        $conventionSource = $convention?->resolver !== null
            ? class_basename($convention->resolver)
            : 'convention';

        $resolved = ResolvedField::merge('summary', [
            $this->candidate($methodAttr, '#[Summary] (method)', 'author override'),
            $this->candidate($descriptor->summary, 'docblock', 'method docblock summary'),
            $this->candidate($classAttr, '#[Summary] (class)', 'class-level override'),
            $this->candidate(
                $conventionSummary,
                $conventionSource,
                $this->conventionReason($descriptor),
                $conventionSummary !== null ? "convention '{$conventionSummary}'" : null,
            ),
        ]);

        if ($resolved === null) {
            return [null, null];
        }

        /** @var string $summary */
        $summary = $resolved->value;

        return [$summary, $resolved->toProvenance($summary)];
    }

    /**
     * A present {@see FieldCandidate} when `$value` is non-null, else an absent one. Absent
     * candidates never win and are never recorded as superseded, so their `$reason` is cosmetic.
     */
    private function candidate(
        mixed $value,
        string $source,
        string $reason,
        ?string $supersededLabel = null,
    ): FieldCandidate {
        return $value === null
            ? FieldCandidate::absent($source, $reason)
            : FieldCandidate::present($value, $source, $reason, $supersededLabel);
    }

    /**
     * Provenance for the resolved success status code: explicit `#[Response(2xx)]`, a body-scanned
     * explicit status, a content-bearing body overriding the conventional 204, the convention, or
     * the `200` fallback.
     */
    private function statusProvenance(
        string $status,
        bool $hasResponseOverride,
        bool $bodyScannedExplicit,
        bool $conventionSuppressedByBody,
        ?ResolvedConvention $convention,
        ActionDescriptor $descriptor,
    ): FieldProvenance {
        // The winning status value is already resolved; exactly one branch decided it, so only that
        // branch's candidate is present. Status records no superseded candidates (matching today's
        // provenance), which holds because every non-deciding branch is absent — including the
        // convention candidate when a higher-precedence branch (an explicit #[Response], a
        // body-scanned status, or a content-bearing body suppressing a 204) already won, and the
        // `default` fallback. A richer superseded record for status belongs to #484, not this
        // byte-identical PR.
        $higherBranchWon = $hasResponseOverride || $bodyScannedExplicit || $conventionSuppressedByBody;
        $conventional = !$higherBranchWon && $convention?->convention->successStatusCode !== null;
        $default = !$higherBranchWon && !$conventional;

        $resolved = ResolvedField::merge('status', [
            $hasResponseOverride
                ? FieldCandidate::present($status, '#[Response] (method)', 'author override')
                : FieldCandidate::absent('#[Response] (method)', 'no 2xx response attribute'),
            $bodyScannedExplicit
                ? FieldCandidate::present($status, 'response body', 'explicit status in handler body')
                : FieldCandidate::absent('response body', 'no explicit body status'),
            $conventionSuppressedByBody
                ? FieldCandidate::present(
                    $status,
                    'response body',
                    'content-bearing body overrides the conventional 204',
                )
                : FieldCandidate::absent('response body', 'convention not suppressed by body'),
            $conventional
                ? FieldCandidate::present(
                    $status,
                    class_basename($convention->resolver),
                    $this->conventionReason($descriptor),
                )
                : FieldCandidate::absent('convention', 'no convention status'),
            $default
                ? FieldCandidate::present($status, 'default', 'no convention matched')
                : FieldCandidate::absent('default', 'a higher-precedence branch decided'),
        ]);

        assert($resolved !== null);

        return $resolved->toProvenance($status);
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
        $replaces = $operationOverride?->tags !== null && $operationOverride->replace;
        $merges = $operationOverride?->tags !== null && !$replaces;
        $tagAttributes = !$merges && !$replaces && $additionalTags !== [];

        // Exactly one branch describes how the tag list was formed; the rest are absent, so tags
        // record no superseded candidates (matching today's provenance).
        $resolved = ResolvedField::merge('tags', [
            $replaces
                ? FieldCandidate::present($tags, '#[Operation] (replace)', 'attribute tags replace controller-derived')
                : FieldCandidate::absent('#[Operation] (replace)', 'no replacing #[Operation] tags'),
            $merges
                ? FieldCandidate::present($tags, '#[Operation] (merge)', 'attribute tags merged with controller-derived')
                : FieldCandidate::absent('#[Operation] (merge)', 'no merging #[Operation] tags'),
            $tagAttributes
                ? FieldCandidate::present($tags, '#[Tag]', 'attribute tags merged with controller-derived')
                : FieldCandidate::absent('#[Tag]', 'no #[Tag] attributes'),
            !$replaces && !$merges && !$tagAttributes
                ? FieldCandidate::present($tags, 'controller-derived', 'controller short name')
                : FieldCandidate::absent('controller-derived', 'a tag attribute decided'),
        ]);

        assert($resolved !== null);

        return $resolved->toProvenance($value);
    }

    /**
     * Provenance for a `true` deprecation flag: the `#[Deprecated]`/native `\Deprecated` attribute
     * wins over the `@deprecated` docblock tag. Absent when the operation is not deprecated.
     */
    private function deprecatedProvenance(ActionDescriptor $descriptor, ?string $deprecation): ?FieldProvenance
    {
        if ($deprecation === null) {
            return null;
        }

        $hasAttribute = $this->firstDeprecatedAttribute($descriptor) !== null;

        $resolved = ResolvedField::merge('deprecated', [
            $hasAttribute
                ? FieldCandidate::present(true, '#[Deprecated]', 'author override')
                : FieldCandidate::absent('#[Deprecated]', 'no deprecation attribute'),
            FieldCandidate::present(true, 'docblock', '@deprecated tag'),
        ]);

        assert($resolved !== null);

        return $resolved->toProvenance('true');
    }

    /**
     * Provenance for an author-set `operationId`. Only `#[Operation(operationId)]` supplies one, so
     * this is a single-source entry; absent when unset.
     */
    private function operationIdProvenance(?string $operationId): ?FieldProvenance
    {
        $resolved = ResolvedField::merge('operationId', [
            $this->candidate($operationId, '#[Operation]', 'author override'),
        ]);

        return $resolved?->toProvenance($operationId ?? '');
    }

    /**
     * Provenance for an author-set `externalDocs`. Only `#[ExternalDocs]` supplies it; absent when
     * unset.
     */
    private function externalDocsProvenance(?OA\ExternalDocumentation $externalDocs): ?FieldProvenance
    {
        if ($externalDocs === null) {
            return null;
        }

        $url = is_string($externalDocs->url) ? $externalDocs->url : '';
        $resolved = ResolvedField::merge('externalDocs', [
            FieldCandidate::present($url, '#[ExternalDocs]', 'author override'),
        ]);

        assert($resolved !== null);

        return $resolved->toProvenance($url);
    }

    /**
     * Provenance for the resolved `security`. Precedence mirrors {@see resolveSecurity()}:
     * `#[PublicEndpoint]` (empty requirement) → `#[Security]` (method shadows class) → middleware.
     * Absent when security is omitted (`null`): an unauthenticated, non-public operation.
     *
     * @param null|list<array<string, list<string>>> $security
     */
    private function securityProvenance(ActionDescriptor $descriptor, ?array $security): ?FieldProvenance
    {
        if ($security === null) {
            return null;
        }

        $isPublic = $this->hasAttribute($descriptor, PublicEndpoint::class);
        $hasAttribute = !$isPublic && (
            $descriptor->actionAttributes(SecurityAttribute::class) !== []
            || $descriptor->controllerAttributes(SecurityAttribute::class) !== []
        );
        $value = $this->securityDisplayValue($security);
        // An empty requirement is public regardless of source; a non-empty one came from middleware
        // (the attribute and public cases are handled by the higher-precedence candidates).
        $middlewareReason = $security === [] ? 'no auth middleware on the route' : 'auth middleware on the route';

        $resolved = ResolvedField::merge('security', [
            $isPublic
                ? FieldCandidate::present($security, '#[PublicEndpoint]', 'declared public')
                : FieldCandidate::absent('#[PublicEndpoint]', 'not marked public'),
            $hasAttribute
                ? FieldCandidate::present($security, '#[Security]', 'author override')
                : FieldCandidate::absent('#[Security]', 'no security attribute'),
            !$isPublic && !$hasAttribute
                ? FieldCandidate::present($security, 'middleware', $middlewareReason)
                : FieldCandidate::absent('middleware', 'a higher-precedence source decided'),
        ]);

        assert($resolved !== null);

        return $resolved->toProvenance($value);
    }

    /**
     * A compact display string for a security requirement list: the scheme names, or `public` for
     * the empty (explicitly public) requirement.
     *
     * @param list<array<string, list<string>>> $security
     */
    private function securityDisplayValue(array $security): string
    {
        if ($security === []) {
            return 'public';
        }

        $schemes = [];

        foreach ($security as $requirement) {
            foreach (array_keys($requirement) as $scheme) {
                $schemes[$scheme] = true;
            }
        }

        return implode(', ', array_keys($schemes));
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

    /**
     * Same precedence as {@see resolveSummary()}: method `#[Description]`/`#[Operation]` → docblock
     * → class `#[Description]`/`#[Operation]`. Returns the resolved description and its provenance
     * (null provenance when no source supplied one).
     *
     * @return array{0: ?string, 1: ?FieldProvenance}
     */
    private function resolveDescription(ActionDescriptor $descriptor): array
    {
        $methodAttr = $this->readScopedDescription(
            $descriptor->actionAttributes(DescriptionAttribute::class),
            $descriptor->actionAttributes(OperationAttribute::class),
        );
        $classAttr = $this->readScopedDescription(
            $descriptor->controllerAttributes(DescriptionAttribute::class),
            $descriptor->controllerAttributes(OperationAttribute::class),
        );

        $resolved = ResolvedField::merge('description', [
            $this->candidate($methodAttr, '#[Description] (method)', 'author override'),
            $this->candidate($descriptor->description, 'docblock', 'method docblock description'),
            $this->candidate($classAttr, '#[Description] (class)', 'class-level override'),
        ]);

        if ($resolved === null) {
            return [null, null];
        }

        /** @var string $description */
        $description = $resolved->value;

        return [$description, $resolved->toProvenance($description)];
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
}
