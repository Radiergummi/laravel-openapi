<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Deprecated as NativeDeprecated;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Attributes\BaseExample as BaseExampleAttribute;
use Radiergummi\OpenApi\Core\Attributes\Deprecated as DeprecatedAttribute;
use Radiergummi\OpenApi\Core\Attributes\Example as ExampleAttribute;
use Radiergummi\OpenApi\Core\Attributes\ExternalDocs as ExternalDocsAttribute;
use Radiergummi\OpenApi\Core\Attributes\Header as HeaderAttribute;
use Radiergummi\OpenApi\Core\Attributes\Link as LinkAttribute;
use Radiergummi\OpenApi\Core\Attributes\Operation as OperationAttribute;
use Radiergummi\OpenApi\Core\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Core\Attributes\RequestBody as RequestBodyAttribute;
use Radiergummi\OpenApi\Core\Attributes\Response as ResponseAttribute;
use Radiergummi\OpenApi\Core\Attributes\ResponseExample as ResponseExampleAttribute;
use Radiergummi\OpenApi\Core\Attributes\ResponseHeader as ResponseHeaderAttribute;
use Radiergummi\OpenApi\Core\Attributes\Security as SecurityAttribute;
use Radiergummi\OpenApi\Core\Attributes\Tag as TagAttribute;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Extractors\RequestBodyExtractor;
use Radiergummi\OpenApi\Core\Extractors\SecurityExtractor;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Extractors\UriParametersExtractor;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\UriParameterResolver;
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

/**
 * Assembles the per-operation property bag for a single route descriptor.
 *
 * {@see build()} returns the property array that {@see OpenApiGenerator} dispatches onto an
 * HTTP-method-specific annotation subclass (OA\Get, OA\Post, etc.).
 *
 * Authoring overrides are layered on top of auto-derived metadata in this order:
 * 1. Auto-derived defaults (docblock, namespace tag, route name) from {@see ActionDescriptor}.
 * 2. Class-level {@see OperationAttribute} — fields override defaults.
 * 3. Method-level {@see OperationAttribute} — fields override class-level.
 * 4. {@see TagAttribute}s (class + method) — merged onto the tag set from steps 1–3.
 * 5. {@see ResponseAttribute}s (method only) — appended to the responses list.
 * 6. PHP 8.4 native `Deprecated` (method, else class) — sets `deprecated: true`.
 */
final readonly class OperationBuilder
{
    public function __construct(
        private UriParameterResolver $uriResolver,
        private UriParametersExtractor $uriExtractor,
        private RequestBodyExtractor $bodyExtractor,
        private SecurityExtractor $securityExtractor,
        private StandardResponsesExtractor $standardResponsesExtractor,
        private ExampleFileLoader $fileLoader,
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
                        ),
                        $parameter,
                    ],
                $action->uriParameters,
            ),
        );
        $queryParams = [];

        foreach ($this->queryParameterResolvers as $queryResolver) {
            foreach ($queryResolver->resolveQueryParameters($action) as $param) {
                $queryParams[] = $param;
            }
        }

        $security = $this->resolveSecurity($action);
        $headerParams = $this->readHeaderAttributes($action);
        $requestBody = $this->bodyExtractor->extractFromMethod($action);
        $requestBody = $this->applyRequestBodyOverride($action, $requestBody);
        $this->applyRequestExamples($action, $requestBody);

        // Consult registered primary-response resolvers, first non-null wins.
        $autoPrimaryResponse = null;

        foreach ($this->primaryResponseResolvers as $responseResolver) {
            $autoPrimaryResponse = $responseResolver->resolvePrimaryResponse($action);

            if ($autoPrimaryResponse !== null) {
                break;
            }
        }

        $operationOverride = $this->readOperationAttribute($action);
        $additionalTags = $this->readTagAttributes($action);
        $additionalResponses = $this->readResponseAttributes($action);
        $standardResponses = $this->standardResponsesExtractor->extract($action);
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

        $additionalResponses = $filteredAdditional;
        $primaryResponse = $primaryOverride
            ?? $autoPrimaryResponse
            ?? new OA\Response(['response' => '200', 'description' => 'OK']);

        // Order matters: standard responses first, explicit Response attributes last.
        // swagger-php's last-write-wins serialization lets #[Response] override generated 401/404.
        $responses = [$primaryResponse, ...$standardResponses, ...$additionalResponses];
        $this->applyResponseExamples($action, $responses);
        $this->applyResponseHeaders($action, $responses);
        $this->applyLinkAttributes($action, $primaryResponse);

        if ($operationOverride !== null) {
            $summary = $operationOverride->summary ?? $action->summary;
            $description = $operationOverride->description ?? $action->description;
            $baseTags = match (true) {
                // Explicit opt-in: discard namespace-derived tags.
                $operationOverride->tags !== null && $operationOverride->replace => $operationOverride->tags,

                // Default: merge attribute tags with namespace-derived tags.
                $operationOverride->tags !== null => $this->mergeTags(
                    $defaultTags,
                    $operationOverride->tags,
                ),
                default => $defaultTags,
            };
        } else {
            $summary = $action->summary;
            $description = $action->description;
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
     * Resolves security requirements for an operation.
     *
     * Precedence (first match wins):
     * 1. {@see PublicEndpoint} on method or class → `[]` (truly public)
     * 2. {@see SecurityAttribute} on method or class → declared scopes as OR alternatives.
     * 3. Middleware-derived security via {@see SecurityExtractor}.
     *
     * @return list<array<string, list<string>>>
     */
    private function resolveSecurity(ActionDescriptor $descriptor): array
    {
        if ($this->hasAttribute($descriptor, PublicEndpoint::class)) {
            return [];
        }

        $security = $this->readAttribute($descriptor, SecurityAttribute::class);

        if ($security instanceof SecurityAttribute) {
            return $this->securityExtractor->requirementForScopes($security->scopes, $security->scheme);
        }

        return $this->securityExtractor->forRoute($descriptor->route);
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
            ] as $attr
        ) {
            $instance = $attr->newInstance();
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

        foreach ($descriptor->actionAttributes(ExampleAttribute::class) as $attr) {
            try {
                $instances[] = $attr->newInstance();
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

    private function readOperationAttribute(ActionDescriptor $descriptor): ?OperationAttribute
    {
        $source = $descriptor->actionAttributes(OperationAttribute::class)[0]
            ?? $descriptor->controllerAttributes(OperationAttribute::class)[0]
            ?? null;

        $instance = $source?->newInstance();
        assert($instance === null || $instance instanceof OperationAttribute);

        return $instance;
    }

    /** @return list<string> */
    private function readTagAttributes(ActionDescriptor $descriptor): array
    {
        $tags = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(TagAttribute::class),
                ...$descriptor->actionAttributes(TagAttribute::class),
            ] as $attr
        ) {
            $tags[] = $attr->newInstance()->name;
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
            fn(ReflectionAttribute $attr): OA\Response
                => $this->buildResponseFromAttribute(
                    $attr->newInstance(),
                ),
            $descriptor->actionAttributes(ResponseAttribute::class),
        );
    }

    private function buildResponseFromAttribute(ResponseAttribute $attribute): OA\Response
    {
        $props = [
            'response' => (string) $attribute->status,
            'description' => $attribute->description,
        ];

        $mediaType = $attribute->mediaType ?? MediaType::Json;

        // Inline schema wins over $ref when both are supplied.
        if ($attribute->schema !== null) {
            $props['content'] = [
                $mediaType->schema(new OA\Schema($attribute->schema)),
            ];
        } else {
            $schemaRef = $this->resolveRefSchema($attribute->ref);

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
    private function resolveRefSchema(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        foreach ($this->refSchemaResolvers as $resolver) {
            $result = $resolver->resolveRef($ref);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function readDeprecation(ActionDescriptor $descriptor): ?string
    {
        $source = $this->firstDeprecatedAttribute($descriptor);

        if ($source === null) {
            return null;
        }

        $instance = $source->newInstance();

        if ($instance instanceof DeprecatedAttribute) {
            return $instance->reason ?? '';
        }

        // PHP 8.4 native \Deprecated.
        return $instance->message ?? '';
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
     * Attaches `#[ResponseHeader]` attributes to the response whose status matches the attribute's
     * `status:`. Walks both controller and method (or just the function for closure routes) so
     * authors can declare a shared header once on the controller; method-level entries win on
     * `(status, name)` collision and declaration order is otherwise preserved. Headers without a
     * matching response are dropped silently.
     *
     * Per RFC7230, header names are case-insensitive — the swagger-php Header object carries the
     * casing the author chose.
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
     * Attaches `#[Link]` attributes declared on the method to the primary 2xx response.
     *
     * Each link becomes an entry in `responses.{status}.links`, keyed by `$link->name`. Links are
     * only supported on method-level (they are per-operation, not per-controller).
     */
    private function applyLinkAttributes(ActionDescriptor $descriptor, OA\Response $primaryResponse): void
    {
        $attrs = $descriptor->actionAttributes(LinkAttribute::class);

        if ($attrs === []) {
            return;
        }

        $links = is_array($primaryResponse->links) ? $primaryResponse->links : [];

        foreach ($attrs as $attr) {
            $instance = $attr->newInstance();
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
