<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData;

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Enums\PaginatorKind;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use ReflectionException;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

use function class_exists;
use function is_a;
use function sprintf;

/**
 * Resolves a Spatie Data return type into its `200 OK` response.
 *
 * Handles two cases:
 * - `FlightData` (a {@see Data} subclass) → `$ref` to the Data component schema.
 * - `DataCollection<int, FlightData>` → array of `$ref`s, item class read from
 *   the `@return DataCollection<…, Item>` PHPDoc generic.
 *
 * Paginated Spatie collections (`PaginatedDataCollection<…>` /
 * `CursorPaginatedDataCollection<…>`) are recognised by {@see PaginatorKind} and
 * handled by `PaginatorResponseResolver` via the shared `RefSchemaResolver`
 * chain (which includes {@see DataRefSchemaResolver}); this resolver returns
 * null for them so the core resolver claims the route.
 *
 * Returns null when the return type is not a Data class or non-paginating
 * collection, or when the collection's item generic is missing — the next
 * resolver gets a turn.
 *
 * Mirror of {@see \Radiergummi\OpenApi\Plugins\ApiResources\ResourceResponseResolver}
 * for the SpatieData plugin.
 */
final readonly class DataResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private DataRefSchemaResolver $refResolver,
        private ReturnTypeExtractor $returnTypeExtractor,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            return $this->resolve($descriptor);
        } catch (ReflectionException $e) {
            // Tolerate reflection failures only (e.g. a Data class that disappears between
            // attribute resolution and schema build). Real bugs — attribute construction
            // errors, schema-build logic errors — propagate so they surface in dev rather
            // than disappearing into a warning log.
            $this->logger->warning(sprintf(
                'DataResponseResolver reflection failure for route %s: %s',
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

        $returnClass = $returnType->getName();

        // Paginated Spatie collections are claimed by PaginatorResponseResolver.
        if (PaginatorKind::fromClass($returnClass) !== null) {
            return null;
        }

        // Single Data return type.
        if (is_a($returnClass, Data::class, allow_string: true)) {
            /** @var class-string<Data> $returnClass */
            $ref = $this->refResolver->resolveRef($returnClass);

            if ($ref === null) {
                return null;
            }

            return $this->response(new OA\Schema(['ref' => $ref]));
        }

        // Non-paginating DataCollection<int, Item>.
        if (!is_a($returnClass, DataCollection::class, allow_string: true)) {
            return null;
        }

        $itemClass = $this->returnTypeExtractor->genericArgument($reflector);

        if ($itemClass === null || !class_exists($itemClass) || !is_a($itemClass, Data::class, allow_string: true)) {
            $this->logger->warning(sprintf(
                'Route %s returns a Spatie %s but its item type is undeclared; '
                . 'add a `@return %s<Item>` docblock.',
                $descriptor->route->uri(),
                $returnClass,
                $returnClass,
            ));

            return null;
        }

        /** @var class-string<Data> $itemClass */
        $ref = $this->refResolver->resolveRef($itemClass);

        if ($ref === null) {
            return null;
        }

        return $this->response(new OA\Schema([
            'type' => 'array',
            'items' => new OA\Items(['ref' => $ref]),
        ]));
    }

    private function response(OA\Schema $schema): OA\Response
    {
        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]);
    }
}
