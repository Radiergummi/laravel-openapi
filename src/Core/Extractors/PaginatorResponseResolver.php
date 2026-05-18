<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Enums\PaginatorKind;
use Radiergummi\OpenApi\Core\Generator\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use ReflectionNamedType;
use Throwable;

use function class_exists;
use function sprintf;

/**
 * Resolves a paginator return type (`LengthAwarePaginator`, `Paginator`,
 * `CursorPaginator`) into its `200 OK` response.
 *
 * The paginated item type is resolved with this precedence (attribute wins):
 *   1. A `#[ResponseResource]` attribute on the action.
 *   2. The `@return Paginator<Item>` PHPDoc generic argument.
 * When neither is present the resolver logs a generation warning and returns
 * null, deferring to the next resolver (and ultimately the bare-200 fallback).
 */
final readonly class PaginatorResponseResolver implements PrimaryResponseResolver
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(
        private ReturnTypeExtractor $returnTypeExtractor,
        private PaginatorSchemaFactory $schemaFactory,
        private LoggerInterface $logger,
        private array $refSchemaResolvers = [],
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            return $this->resolve($descriptor);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'PaginatorResponseResolver failed for route %s: %s',
                $descriptor->route->uri(),
                $e->getMessage(),
            ));

            return null;
        }
    }

    private function resolve(ActionDescriptor $descriptor): ?OA\Response
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
            return null;
        }

        $itemClass = $this->resolveItemClass($descriptor);

        if ($itemClass === null) {
            $this->logger->warning(sprintf(
                'Route %s returns a paginator but its item type is undeclared; '
                . 'add #[ResponseResource(...)] or a @return Paginator<Item> docblock.',
                $descriptor->route->uri(),
            ));

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
     * @return null|class-string
     */
    private function resolveItemClass(ActionDescriptor $descriptor): ?string
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector !== null) {
            $attribute = $reflector->getAttributes(ResponseResource::class)[0] ?? null;

            if ($attribute !== null) {
                $instance = $attribute->newInstance();

                if (class_exists($instance->class)) {
                    return $instance->class;
                }
            }

            $generic = $this->returnTypeExtractor->genericArgument($reflector);

            if ($generic !== null && class_exists($generic)) {
                return $generic;
            }
        }

        return null;
    }

    /**
     * Turns the item class into an `OA\Items`: a `$ref` when a registered
     * resolver claims the class, otherwise a generic object item.
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
