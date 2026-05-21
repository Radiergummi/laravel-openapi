<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Attributes\Webhook as WebhookAttribute;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Extensions\OperationContext;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use ReflectionAttribute;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_values;
use function assert;
use function config;
use function in_array;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function strtolower;
use function strtoupper;
use function ucfirst;

/**
 * Generates an OpenAPI 3.1 document from the application's route definitions.
 *
 * Consumes {@see RouteIntrospector::discover()} (the same data source as `route:sync`) and
 * assembles a swagger-php {@see OA\OpenApi} instance that callers can serialise to YAML or JSON.
 */
final readonly class OpenApiGenerator
{
    public function __construct(
        private RouteIntrospector $introspector,
        private OperationBuilder $operationBuilder,
        private ComponentSchemaRegistry $schemaRegistry,
    ) {}

    /**
     * Generates the OpenAPI document.
     *
     * The build runs with a swagger-php {@see Context} pinned to OpenAPI 3.1. swagger-php's
     * context defaults to 3.0, under which serialisation down-converts a 3.1 nullable type
     * union (`type: ['string', 'null']`) to the `nullable: true` keyword that 3.1 removed.
     * Installing a 3.1 context for the duration of the build keeps the 3.1 type-union form.
     *
     * @param list<callable(ActionDescriptor): bool> $filters Additional filters applied on top
     *                                                        of RouteIntrospector's defaults.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generate(array $filters = []): OA\OpenApi
    {
        $previousContext = Generator::$context;
        Generator::$context = new Context(['version' => OA\OpenApi::VERSION_3_1_0]);

        try {
            return $this->assembleDocument($filters);
        } finally {
            Generator::$context = $previousContext;
        }
    }

    /**
     * Builds and returns the OpenAPI document tree.
     *
     * @param list<callable(ActionDescriptor): bool> $filters
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function assembleDocument(array $filters): OA\OpenApi
    {
        $this->schemaRegistry->reset();
        $this->operationBuilder->reset();
        $this->introspector->reset();

        /** @var array<string, OA\PathItem> $pathItems Keyed by URI path */
        $pathItems = [];

        /** @var array<string, OA\Webhook> $webhookItems Keyed by logical webhook name */
        $webhookItems = [];

        foreach ($this->introspector->discover() as $descriptor) {
            if ($this->shouldSkip($descriptor, $filters)) {
                continue;
            }

            $webhookAttr = $this->readWebhookAttribute($descriptor);

            if ($webhookAttr !== null) {
                $name = $webhookAttr->name;

                if (!isset($webhookItems[$name])) {
                    $webhookItems[$name] = new OA\Webhook(['webhook' => $name]);
                }

                $this->attachOperation($webhookItems[$name], $descriptor);

                continue;
            }

            $path = $this->normalisePath($descriptor->route->uri());

            if (!isset($pathItems[$path])) {
                $pathItems[$path] = new OA\PathItem(['path' => $path]);
            }

            $this->attachOperation($pathItems[$path], $descriptor);
        }

        $componentSchemas = $this->schemaRegistry->all();
        $componentResponses = $this->schemaRegistry->allResponses();

        $componentsProps = ['securitySchemes' => $this->operationBuilder->buildSecuritySchemes()];

        if ($componentSchemas !== []) {
            $componentsProps['schemas'] = $componentSchemas;
        }

        if ($componentResponses !== []) {
            $componentsProps['responses'] = $componentResponses;
        }

        $documentProps = [
            'openapi' => '3.1.0',
            'info' => $this->buildInfo(),
            'servers' => $this->buildServers(),
            'paths' => array_values($pathItems),
            'components' => new OA\Components($componentsProps),
        ];

        if ($webhookItems !== []) {
            $documentProps['webhooks'] = array_values($webhookItems);
        }

        $tags = $this->buildTags();

        if ($tags !== []) {
            $documentProps['tags'] = $tags;
        }

        $document = new OA\OpenApi($documentProps);

        OpenApiExtensions::applyDocumentTransformers($document);

        return $document;
    }

    private function buildInfo(): OA\Info
    {
        /** @var array<string, mixed> $info */
        $info = (array) config('openapi.info', []);

        $info['title'] ??= config('app.name', 'API');
        $info['version'] ??= '0.0.0';

        if (isset($info['contact']) && is_array($info['contact'])) {
            $info['contact'] = new OA\Contact($info['contact']);
        }

        if (isset($info['license']) && is_array($info['license'])) {
            $info['license'] = new OA\License($info['license']);
        }

        return new OA\Info($info);
    }

    /**
     * @return list<OA\Server>
     */
    private function buildServers(): array
    {
        /** @var list<array<string, mixed>> $servers */
        $servers = (array) config('openapi.servers', []);

        if ($servers === []) {
            return [new OA\Server(['url' => config('app.url')])];
        }

        return array_values(
            array_map(
                static fn(array $server): OA\Server => new OA\Server($server),
                $servers,
            ),
        );
    }

    /**
     * @return list<OA\Tag>
     */
    private function buildTags(): array
    {
        /** @var array<string, array<string, mixed>> $tags */
        $tags = (array) config('openapi.tags', []);

        $result = [];

        foreach ($tags as $name => $config) {
            $props = ['name' => $name] + $config;

            if (isset($props['externalDocs']) && is_array($props['externalDocs'])) {
                $props['externalDocs'] = new OA\ExternalDocumentation($props['externalDocs']);
            }

            $result[] = new OA\Tag($props);
        }

        return $result;
    }

    /**
     * @param list<callable(ActionDescriptor): bool> $filters
     */
    private function shouldSkip(ActionDescriptor $descriptor, array $filters): bool
    {
        if ($this->isHidden($descriptor)) {
            return true;
        }

        return array_any($filters, static fn(callable $filter): bool => $filter($descriptor));
    }

    private function isHidden(ActionDescriptor $descriptor): bool
    {
        $environment = app()->environment();

        if (
            $descriptor->actionReflector !== null
            && $this->matchesHide(
                $descriptor->actionReflector->getAttributes(Hide::class),
                $environment,
            )
        ) {
            return true;
        }

        return
            $descriptor->controller !== null
            && $this->matchesHide(
                $descriptor->controller->getAttributes(Hide::class),
                $environment,
            );
    }

    /**
     * @param ReflectionAttribute<Hide>[] $attributes
     */
    private function matchesHide(array $attributes, string $env): bool
    {
        foreach ($attributes as $attribute) {
            $hide = $attribute->newInstance();

            if ($hide->only === null && $hide->except === null) {
                return true;
            }

            if ($hide->only !== null && in_array($env, $hide->only, true)) {
                return true;
            }

            if ($hide->except !== null && !in_array($env, $hide->except, true)) {
                return true;
            }
        }

        return false;
    }

    private function readWebhookAttribute(ActionDescriptor $descriptor): ?WebhookAttribute
    {
        if ($descriptor->actionReflector === null) {
            return null;
        }

        $attribute = ($descriptor->actionReflector->getAttributes(WebhookAttribute::class)[0] ?? null)?->newInstance();
        assert($attribute === null || $attribute instanceof WebhookAttribute);

        return $attribute;
    }

    private function normalisePath(string $uri): string
    {
        return Str::start($uri, '/');
    }

    /**
     * HEAD is skipped, as it's implicit in OpenAPI whenever GET is present.
     *
     * swagger-php uses per-method annotation subclasses (OA\Get, OA\Post, …) rather than the
     * abstract {@see OA\Operation} base; we assign to the matching PathItem property via `match()`.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function attachOperation(OA\PathItem $pathItem, ActionDescriptor $action): void
    {
        $operation = $this->operationBuilder->build($action, [
            $this->deriveTag($action),
        ]);

        foreach ($action->route->methods() as $method) {
            $upper = strtoupper($method);

            if ($upper === 'HEAD') {
                continue;
            }

            // Resolve the operationId per method without mutating $operation —
            // a multi-method route must not reuse the first method's id.
            $resolved = $operation->operationId !== null
                ? $operation
                : $operation->withOperationId(
                    $this->buildOperationId($action, $upper),
                );

            $operationSchema = $resolved->toOpenApi($method);

            if ($operationSchema === null) {
                continue;
            }

            OpenApiExtensions::applyOperationTransformers(
                $operationSchema,
                new OperationContext($action, $upper),
            );

            $pathItem->{strtolower($upper)} = $operationSchema;
        }
    }

    /**
     * Builds an operation ID for the given route.
     *
     * Priority:
     * 1. Named route → `{name}.{method}` for multi-method routes, plain `{name}` otherwise.
     * 2. Generated/unnamed (`generated::*` prefix or null) → `{method}_{sanitised_path}`.
     */
    private function buildOperationId(ActionDescriptor $descriptor, string $method): string
    {
        $name = $descriptor->route->getName();
        $methods = array_filter(
            $descriptor->route->methods(),
            static fn(string $m): bool => strtoupper($m) !== 'HEAD',
        );

        if ($name !== null && !Str::startsWith($name, 'generated::')) {
            return count($methods) > 1
                ? $name . '.' . strtolower($method)
                : $name;
        }

        $sanitised = preg_replace('/[^a-zA-Z0-9]+/', '_', $descriptor->route->uri())
            ?? $descriptor->route->uri();

        return strtolower($method) . '_' . $sanitised;
    }

    /**
     * Walks the FQCN in reverse and returns the first segment that is neither the controller class
     * itself nor generic scaffolding (Http, App, Internal, External, Global, version segments
     * like V0).
     *
     * Examples:
     *   App\Http\Controllers\Internal\Projects\ProjectsController → Projects
     *   App\Http\Controllers\External\V0\Companies\ExternalCompaniesController → Companies
     *   App\Http\Controllers\Global\Auth\AuthController → Auth
     *
     * Falls back to "General" for closure routes (no controller available).
     */
    private function deriveTag(ActionDescriptor $descriptor): string
    {
        if ($descriptor->controller === null) {
            return 'General';
        }

        $skipParts = ['Controllers', 'Http', 'App', 'Internal', 'External', 'Global'];

        foreach (array_reverse(explode('\\', $descriptor->controller->getName())) as $part) {
            if ($part === '' || str_ends_with($part, 'Controller')) {
                continue;
            }

            if (preg_match('/^V\d+$/', $part) || in_array($part, $skipParts, strict: true)) {
                continue;
            }

            return ucfirst($part);
        }

        return 'General';
    }
}
