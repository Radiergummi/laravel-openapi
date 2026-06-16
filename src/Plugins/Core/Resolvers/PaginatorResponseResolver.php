<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorCallReader;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

use function class_exists;
use function is_a;
use function sprintf;

/**
 * Resolves a paginator return type (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) into
 * its `200 OK` response.
 *
 * The paginated item type is resolved with this precedence (attribute wins):
 *   1. A `#[ResponseResource]` attribute on the action.
 *   2. The `return Paginator<Item>` PHPDoc generic argument.
 * When neither is present the resolver logs a generation warning and returns null, deferring to
 * the next resolver (and ultimately the bare-200 fallback).
 *
 * When the return type is not itself a paginator, the resolver falls back to the body scan
 * ({@see PaginatorCallReader}) — but only for actions ApiResources / SpatieData would not claim
 * (their resource / Data return types and resource-naming `#[ResponseResource]`). Core runs first,
 * so without those guards it would steal responses those plugins shape better (issue #353).
 */
final readonly class PaginatorResponseResolver implements PrimaryResponseResolver
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(
        private ReturnTypeExtractor $returnTypeExtractor,
        private PaginatorSchemaFactory $schemaFactory,
        private PaginatorCallReader $paginatorCallReader,
        private LoggerInterface $logger,
        private array $refSchemaResolvers = [],
    ) {}

    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $returnType = $reflector->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $kind = PaginatorKind::fromClass($returnType->getName());

        if ($kind === null) {
            // The return type is not itself a paginator. Fall back to the body scan only when the
            // action is not one ApiResources / SpatieData would claim — otherwise Core (which runs
            // first) would steal a response those plugins shape better. Both guards return null,
            // preserving the pre-#353 behaviour.
            $kind = $this->kindFromBody($descriptor, $reflector, $returnType);

            if ($kind === null) {
                return null;
            }
        }

        $itemClass = $this->resolveItemClass($reflector);

        if ($itemClass === null) {
            $this->logger->warning(
                sprintf(
                    'Route %s returns a paginator but its item type is undeclared; '
                    . 'add #[ResponseResource(...)] or a @return Paginator<Item> docblock.',
                    $descriptor->route->uri(),
                ),
            );

            return null;
        }

        $envelope = $this->schemaFactory->envelope($kind, $this->itemsFor($itemClass));

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]);
    }

    /**
     * The paginator kind from an unconditional `paginate()`-family call in the action body, but
     * only when the action is not one ApiResources / SpatieData would claim. Both guards return
     * null to leave the response to those plugins (and ultimately the bare-200 fallback).
     */
    private function kindFromBody(
        ActionDescriptor $descriptor,
        ReflectionFunctionAbstract $reflector,
        ReflectionNamedType $returnType,
    ): ?PaginatorKind {
        // Guard 1 — a resource / Data return type is the convention plugins' surface.
        if ($this->isResourceOrDataType($returnType->getName())) {
            return null;
        }

        // Guard 2 — a method- or controller-level #[ResponseResource] naming a JsonResource is the
        // ApiResources claiming surface. This deliberately mirrors
        // ResourceClassLocator::readResponseResource()'s two-level read (documented coupling): Core
        // may consume #[ResponseResource] for the item class only when it names a non-resource bare
        // model, which resolveItemClass() handles below.
        if ($this->namesResponseResourceClass($reflector, $descriptor)) {
            return null;
        }

        // The body scan needs a concrete method (closures carry no paginate() controller idiom).
        if (!$reflector instanceof ReflectionMethod) {
            return null;
        }

        return $this->paginatorCallReader->detect($reflector);
    }

    /**
     * Whether the class is a Laravel API Resource or a Spatie Data type — matched by FQCN string so
     * Core stays free of plugin / third-party imports (the same approach as {@see PaginatorKind}).
     */
    private function isResourceOrDataType(string $class): bool
    {
        foreach (
            [
                'Illuminate\\Http\\Resources\\Json\\JsonResource',
                'Illuminate\\Http\\Resources\\Json\\ResourceCollection',
                'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection',
                'Spatie\\LaravelData\\Data',
                'Spatie\\LaravelData\\DataCollection',
                'Spatie\\LaravelData\\PaginatedDataCollection',
                'Spatie\\LaravelData\\CursorPaginatedDataCollection',
            ] as $type
        ) {
            if (is_a($class, $type, allow_string: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a method- or controller-level `#[ResponseResource]` names a `JsonResource` class.
     * Mirrors {@see \Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceClassLocator}'s
     * two-level attribute read.
     */
    private function namesResponseResourceClass(
        ReflectionFunctionAbstract $reflector,
        ActionDescriptor $descriptor,
    ): bool {
        $attribute = $reflector->getAttributes(ResponseResource::class)[0] ?? null;

        if ($attribute === null && $descriptor->controller !== null) {
            $attribute = $descriptor->controller->getAttributes(ResponseResource::class)[0] ?? null;
        }

        if ($attribute === null) {
            return false;
        }

        $class = $attribute->newInstance()->class;

        return class_exists($class)
            && is_a($class, 'Illuminate\\Http\\Resources\\Json\\JsonResource', allow_string: true);
    }

    /**
     * @return null|class-string
     */
    private function resolveItemClass(ReflectionFunctionAbstract $reflector): ?string
    {
        $attribute = $reflector->getAttributes(ResponseResource::class)[0] ?? null;

        if ($attribute !== null) {
            $instance = $attribute->newInstance();

            // $instance->collection is intentionally not consulted here — a paginator envelope is
            // always a collection by definition.
            if (class_exists($instance->class)) {
                return $instance->class;
            }

            $this->logger->warning(
                sprintf(
                    '#[ResponseResource] on a paginator action references unknown class %s; falling back to the @return generic.',
                    $instance->class,
                ),
            );
        }

        $generic = $this->returnTypeExtractor->genericArgument($reflector);

        if ($generic !== null && class_exists($generic)) {
            return $generic;
        }

        return null;
    }

    /**
     * Turns the item class into an `OA\Items`: a `$ref` when a registered resolver claims the
     * class, otherwise a generic object item.
     *
     * @param class-string $itemClass
     */
    private function itemsFor(string $itemClass): OA\Items
    {
        foreach ($this->refSchemaResolvers as $resolver) {
            $ref = $resolver->resolveRef($itemClass);

            if ($ref !== null) {
                return new OA\Items(['ref' => $ref]);
            }
        }

        // No registered ref resolver claimed the class (e.g. a plain model). Return a generic
        // object schema — not a warning, as this is a valid outcome when the item type is outside
        // the resolver chain.
        return new OA\Items(['type' => 'object']);
    }
}
