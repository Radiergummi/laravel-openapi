<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Attributes\Webhook as WebhookAttribute;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Events\RouteSkipped;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\OperationContext;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\TagDeriver;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_filter;
use function array_values;
use function assert;
use function config;
use function count;
use function preg_replace;
use function strtolower;

/**
 * Walks discovered routes, building `paths` and `webhooks` entries.
 *
 * Owns operation-level transformer dispatch and operationId derivation. Skipped routes
 * dispatch {@see RouteSkipped} for any registered listener.
 *
 * @internal
 */
#[Scoped]
final readonly class PathsStage implements SpecStage
{
    public function __construct(
        private RouteIntrospector $introspector,
        private OperationBuilder $operationBuilder,
        private InclusionEvaluator $evaluator,
        private Dispatcher $events,
        private TagDeriver $tagDeriver = new TagDeriver(),
    ) {}

    /**
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        /** @var array<string, OA\PathItem> $pathItems */
        $pathItems = [];
        /** @var array<string, OA\Webhook> $webhookItems */
        $webhookItems = [];

        foreach ($this->introspector->discover() as $descriptor) {
            $decision = $this->evaluator->decide($descriptor, $context->spec, $context->environment);

            if (!$decision->included) {
                if ($this->events->hasListeners(RouteSkipped::class)) {
                    assert($decision->reason !== null);
                    $this->events->dispatch(
                        new RouteSkipped(
                            route: $descriptor->route,
                            spec: $context->spec->name,
                            reason: $decision->reason,
                            summary: $decision->summary,
                        ),
                    );
                }

                continue;
            }

            $webhookAttr = $this->readWebhookAttribute($descriptor);

            if ($webhookAttr !== null) {
                $name = $webhookAttr->name;
                $webhookItems[$name] ??= new OA\Webhook(['webhook' => $name]);
                $this->attachOperation($webhookItems[$name], $descriptor, $context);

                continue;
            }

            $path = $this->normalisePath($descriptor->route->uri());
            $pathItems[$path] ??= new OA\PathItem(['path' => $path]);
            $this->attachOperation($pathItems[$path], $descriptor, $context);
        }

        $document->paths = array_values($pathItems);

        if ($webhookItems !== []) {
            $document->webhooks = array_values($webhookItems);
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
        $tag = $this->tagDeriver->derive($action);

        foreach ($action->route->methods() as $routeMethod) {
            $method = HttpMethod::fromString($routeMethod) ?? HttpMethod::Get;

            if ($method === HttpMethod::Head) {
                continue;
            }

            // Build per verb so verb-conditional inference sees the verb being emitted, not the
            // route's first. Single-verb routes (GET + skipped HEAD) still build once.
            $verbAction = $action->withHttpMethod($method);
            $operation = $this->operationBuilder->build($verbAction, [$tag]);

            $resolved = $operation->operationId !== null
                ? $operation
                : $operation->withOperationId($this->buildOperationId($verbAction, $method));

            $operationSchema = $resolved->attachTo($pathItem, $method);

            if ($operationSchema === null) {
                continue;
            }

            $context->bindAction($operationSchema, $verbAction);

            OpenApiExtensions::applyOperationTransformers(
                $operationSchema,
                new OperationContext($verbAction, $method),
            );
        }
    }

    /**
     * Builds an operation ID for the given route, dispatching on the configured
     * `openapi.operation_id_strategy`:
     *
     * - `route-name` (default) → {@see routeNameOperationId}: named route verbatim (sanitised),
     *   unnamed routes fall back to `{method}_{path}`.
     * - `method-path` → {@see methodPathOperationId}: always `{method}_{path}`, ignoring names.
     *
     * Unknown values fall back to the default. Every strategy's output satisfies the
     * `operation.id-invalid-chars` pattern.
     */
    private function buildOperationId(ActionDescriptor $descriptor, HttpMethod $method): string
    {
        if (config('openapi.operation_id_strategy') === 'method-path') {
            return $this->methodPathOperationId($descriptor, $method);
        }

        return $this->routeNameOperationId($descriptor, $method);
    }

    private function methodPathOperationId(ActionDescriptor $descriptor, HttpMethod $method): string
    {
        $sanitised = preg_replace('/[^a-zA-Z0-9]+/', '_', $descriptor->route->uri())
            ?? $descriptor->route->uri();

        return strtolower($method->value) . '_' . $sanitised;
    }

    /**
     * Priority:
     * 1. Named route → `{name}.{method}` for multi-method routes, plain `{name}` otherwise.
     * 2. Generated/unnamed (`generated::*` prefix or null) → `{method}_{sanitised_path}`.
     */
    private function routeNameOperationId(ActionDescriptor $descriptor, HttpMethod $method): string
    {
        $name = $descriptor->route->getName();

        if ($name !== null && !Str::startsWith($name, 'generated::')) {
            $methods = array_filter(
                $descriptor->route->methods(),
                static fn(string $method): bool => HttpMethod::fromString($method) !== HttpMethod::Head,
            );

            $operationId = count($methods) > 1
                ? $name . '.' . strtolower($method->value)
                : $name;

            return $this->sanitiseOperationId($operationId);
        }

        return $this->methodPathOperationId($descriptor, $method);
    }

    /**
     * Sanitises a route-name-derived operation ID so it satisfies the codegen-safe identifier
     * pattern enforced by {@see \Radiergummi\OpenApi\Lint\Rules\OperationIdInvalidChars}
     * (`^[A-Za-z][A-Za-z0-9._-]*$`): characters outside the allowed set (e.g. the `:` from
     * namespaced names, the `{}` from bound segments) are replaced with `_`, and any leading
     * non-letter characters are stripped so the id begins with a letter. The `.`/`-`/`_`
     * characters the pattern permits are preserved, keeping dotted names like `api.users.show`
     * intact.
     */
    private function sanitiseOperationId(string $operationId): string
    {
        $sanitised = preg_replace('/[^A-Za-z0-9._-]+/', '_', $operationId) ?? $operationId;

        return preg_replace('/^[^A-Za-z]+/', '', $sanitised) ?? $sanitised;
    }

    private function normalisePath(string $uri): string
    {
        return Str::start($uri, '/');
    }
}
