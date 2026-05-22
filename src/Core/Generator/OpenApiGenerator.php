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
use Radiergummi\OpenApi\Core\Attributes\Webhook as WebhookAttribute;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Extensions\OperationContext;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
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
        private InclusionEvaluator $evaluator,
    ) {}

    /**
     * Generates the OpenAPI document for the given spec definition and environment.
     *
     * The build runs with a swagger-php {@see Context} pinned to OpenAPI 3.1. swagger-php's
     * context defaults to 3.0, under which serialisation down-converts a 3.1 nullable type
     * union (`type: ['string', 'null']`) to the `nullable: true` keyword that 3.1 removed.
     * Installing a 3.1 context for the duration of the build keeps the 3.1 type-union form.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generate(SpecDefinition $spec, string $environment): OA\OpenApi
    {
        $previousContext = Generator::$context;
        Generator::$context = new Context(['version' => OA\OpenApi::VERSION_3_1_0]);

        try {
            return $this->assembleDocument($spec, $environment);
        } finally {
            Generator::$context = $previousContext;
        }
    }

    /**
     * Builds and returns the OpenAPI document tree.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function assembleDocument(SpecDefinition $spec, string $environment): OA\OpenApi
    {
        /** @var array<string, OA\PathItem> $pathItems Keyed by URI path */
        $pathItems = [];

        /** @var array<string, OA\Webhook> $webhookItems Keyed by logical webhook name */
        $webhookItems = [];

        foreach ($this->introspector->discover() as $descriptor) {
            $decision = $this->evaluator->decide($descriptor, $spec, $environment);

            if (!$decision->included) {
                continue;
            }

            $webhookAttr = $this->readWebhookAttribute($descriptor);

            if ($webhookAttr !== null) {
                $name = $webhookAttr->name;
                $webhookItems[$name] ??= new OA\Webhook(['webhook' => $name]);
                $this->attachOperation($webhookItems[$name], $descriptor);

                continue;
            }

            $path = $this->normalisePath($descriptor->route->uri());
            $pathItems[$path] ??= new OA\PathItem(['path' => $path]);
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
            'openapi'    => '3.1.0',
            'info'       => $spec->info,
            'servers'    => $spec->servers !== [] ? $spec->servers : $this->fallbackServers(),
            'paths'      => array_values($pathItems),
            'components' => new OA\Components($componentsProps),
        ];

        if ($webhookItems !== []) {
            $documentProps['webhooks'] = array_values($webhookItems);
        }

        if ($spec->tags !== []) {
            $documentProps['tags'] = $spec->tags;
        }

        $document = new OA\OpenApi($documentProps);

        OpenApiExtensions::applyDocumentTransformers($document);

        return $document;
    }

    /** @return list<OA\Server> */
    private function fallbackServers(): array
    {
        return [new OA\Server(['url' => (string) config('app.url')])];
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
