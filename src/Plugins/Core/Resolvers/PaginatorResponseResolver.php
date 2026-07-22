<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorCallReader;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\ReturnContainer;
use Radiergummi\OpenApi\Support\Routing\ReturnShapeResolver;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

use function class_exists;
use function is_a;
use function sprintf;

/**
 * Resolves a paginator return type (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) into its `200 OK` response.
 *
 * Item type precedence: `#[ResponseResource]` attribute, then `@return Paginator<Item>` generic.
 * Falls back to the body scan ({@see PaginatorCallReader}) when the return type is not itself a
 * paginator, but only for actions ApiResources / SpatieData would not claim.
 */
final readonly class PaginatorResponseResolver implements PrimaryResponseResolver
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(
        private ReturnTypeExtractor $returnTypeExtractor,
        private ReturnShapeResolver $shapeResolver,
        private PaginatorSchemaFactory $schemaFactory,
        private PaginatorCallReader $paginatorCallReader,
        private LoggerInterface $logger,
        private array $refSchemaResolvers = [],
    ) {}

    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $returnType = $reflector->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        // The descriptor answers "is this a paginator, and which kind" from the native type; a
        // non-paginator return whose body paginates still needs the body scan.
        $shape = $this->shapeResolver->describe($reflector);

        $kind = $shape->container === ReturnContainer::Paginated
            ? $shape->paginatorKind
            : $this->kindFromBody($descriptor, $reflector, $returnType);

        if ($kind === null) {
            return null;
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

        return PrimaryResponse::of(new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]));
    }

    /**
     * Detects paginator kind from the action body, skipping actions ApiResources / SpatieData would claim.
     */
    private function kindFromBody(
        ActionDescriptor $descriptor,
        ReflectionFunctionAbstract $reflector,
        ReflectionNamedType $returnType,
    ): ?PaginatorKind {
        if ($this->isResourceOrDataType($returnType->getName())) {
            return null;
        }

        // Mirrors ResourceClassLocator's two-level read; Core only consumes #[ResponseResource] for bare models.
        if ($this->namesResponseResourceClass($reflector, $descriptor)) {
            return null;
        }

        // Closures don't use the paginate() idiom; body scan requires a concrete method.
        if (!$reflector instanceof ReflectionMethod) {
            return null;
        }

        return $this->paginatorCallReader->detect($reflector);
    }

    /**
     * Whether the class is a Laravel API Resource or Spatie Data type (matched by FQCN string to avoid imports).
     */
    private function isResourceOrDataType(string $class): bool
    {
        /** @noinspection ClassConstantCanBeUsedInspection */
        return array_any([
            'Illuminate\\Http\\Resources\\Json\\JsonResource',
            'Illuminate\\Http\\Resources\\Json\\ResourceCollection',
            'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection',
            'Spatie\\LaravelData\\Data',
            'Spatie\\LaravelData\\DataCollection',
            'Spatie\\LaravelData\\PaginatedDataCollection',
            'Spatie\\LaravelData\\CursorPaginatedDataCollection',
        ], fn(string $type): bool => is_a($class, $type, allow_string: true));
    }

    /**
     * Whether a method- or controller-level `#[ResponseResource]` names a `JsonResource` class.
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
            && is_a($class, JsonResource::class, allow_string: true);
    }

    /**
     * @return null|class-string
     */
    private function resolveItemClass(ReflectionFunctionAbstract $reflector): ?string
    {
        $attribute = $reflector->getAttributes(ResponseResource::class)[0] ?? null;

        if ($attribute !== null) {
            $instance = $attribute->newInstance();

            // $instance->collection is not consulted: a paginator envelope is always a collection.
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
     * Returns a `$ref` item when a resolver claims the class, otherwise a generic object item.
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

        return new OA\Items(['type' => 'object']);
    }
}
