<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Events\RouteSkipped;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\OperationContext;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\OperationIdDeriver;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Generator\TagDeriver;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function array_values;
use function assert;

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
        private OperationIdDeriver $operationIdDeriver = new OperationIdDeriver(),
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

            $webhookName = OverrideMatcher::webhookKeyFor($descriptor);

            if ($webhookName !== null) {
                $webhookItems[$webhookName] ??= new OA\Webhook(['webhook' => $webhookName]);
                $this->attachOperation($webhookItems[$webhookName], $descriptor, $context);

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
                : $operation->withOperationId($this->operationIdDeriver->derive($verbAction, $method));

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

    private function normalisePath(string $uri): string
    {
        return Str::start($uri, '/');
    }
}
