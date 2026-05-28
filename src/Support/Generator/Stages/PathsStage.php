<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\Webhook as WebhookAttribute;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Events\RouteSkipped;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\OperationContext;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_filter;
use function array_reverse;
use function array_values;
use function assert;
use function count;
use function explode;
use function in_array;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function strtolower;
use function strtoupper;
use function ucfirst;

/**
 * Walks discovered routes, building `paths` and `webhooks` entries.
 *
 * Owns operation-level transformer dispatch and operationId derivation. Skipped routes
 * dispatch {@see RouteSkipped} for any registered listener.
 */
#[Scoped]
final readonly class PathsStage implements SpecStage
{
    public function __construct(
        private RouteIntrospector $introspector,
        private OperationBuilder $operationBuilder,
        private InclusionEvaluator $evaluator,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void
    {
        /** @var array<string, OA\PathItem> $pathItems */
        $pathItems = [];
        /** @var array<string, OA\Webhook> $webhookItems */
        $webhookItems = [];

        foreach ($this->introspector->discover() as $descriptor) {
            $decision = $this->evaluator->decide($descriptor, $ctx->spec, $ctx->environment);

            if (!$decision->included) {
                if ($this->events->hasListeners(RouteSkipped::class)) {
                    assert($decision->reason !== null);
                    $this->events->dispatch(new RouteSkipped(
                        route: $descriptor->route,
                        spec: $ctx->spec->name,
                        reason: $decision->reason,
                        summary: $decision->summary,
                    ));
                }

                continue;
            }

            $webhookAttr = $this->readWebhookAttribute($descriptor);

            if ($webhookAttr !== null) {
                $name = $webhookAttr->name;
                $webhookItems[$name] ??= new OA\Webhook(['webhook' => $name]);
                $this->attachOperation($webhookItems[$name], $descriptor, $ctx);

                continue;
            }

            $path = $this->normalisePath($descriptor->route->uri());
            $pathItems[$path] ??= new OA\PathItem(['path' => $path]);
            $this->attachOperation($pathItems[$path], $descriptor, $ctx);
        }

        $doc->paths = array_values($pathItems);

        if ($webhookItems !== []) {
            $doc->webhooks = array_values($webhookItems);
        }
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

    /**
     * HEAD is skipped, as it's implicit in OpenAPI whenever GET is present.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function attachOperation(OA\PathItem $pathItem, ActionDescriptor $action, GenerationContext $context): void
    {
        $operation = $this->operationBuilder->build($action, [$this->deriveTag($action)]);

        foreach ($action->route->methods() as $method) {
            $upper = strtoupper($method);

            if ($upper === 'HEAD') {
                continue;
            }

            $resolved = $operation->operationId !== null
                ? $operation
                : $operation->withOperationId($this->buildOperationId($action, $upper));

            $operationSchema = $resolved->toOpenApi($method);

            if ($operationSchema === null) {
                continue;
            }

            $context->bindAction($operationSchema, $action);

            OpenApiExtensions::applyOperationTransformers(
                $operationSchema,
                new OperationContext($action, $upper),
            );

            $pathItem->{strtolower($upper)} = $operationSchema;
        }
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

    private function normalisePath(string $uri): string
    {
        return Str::start($uri, '/');
    }
}
