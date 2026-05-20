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
use Radiergummi\OpenApi\Core\Generator\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use ReflectionNamedType;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;
use Throwable;

use function class_exists;
use function is_a;
use function sprintf;

/**
 * Resolves a Spatie Data return type into its `200 OK` response.
 *
 * Handles three cases:
 * - `FlightData` (a {@see Data} subclass) → `$ref` to the Data component schema.
 * - `DataCollection<int, FlightData>` → array of `$ref`s, item class read from the
 *   `@return DataCollection<…, Item>` PHPDoc generic.
 * - `PaginatedDataCollection<int, FlightData>` / `CursorPaginatedDataCollection<…>` →
 *   the matching paginator envelope from {@see PaginatorSchemaFactory}.
 *
 * Returns null when the return type is not a Data class or container thereof, or
 * when a collection's item generic is missing — the next resolver gets a turn.
 *
 * Mirror of {@see \Radiergummi\OpenApi\Plugins\ApiResources\ResourceResponseResolver}
 * for the SpatieData plugin.
 */
final readonly class DataResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private DataRefSchemaResolver $refResolver,
        private ReturnTypeExtractor $returnTypeExtractor,
        private PaginatorSchemaFactory $schemaFactory,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            return $this->resolve($descriptor);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'DataResponseResolver failed for route %s: %s',
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

        // Single Data return type.
        if (is_a($returnClass, Data::class, allow_string: true)) {
            /** @var class-string<Data> $returnClass */
            $ref = $this->refResolver->resolveRef($returnClass);

            if ($ref === null) {
                return null;
            }

            return $this->response(new OA\Schema(['ref' => $ref]));
        }

        // DataCollection / PaginatedDataCollection / CursorPaginatedDataCollection.
        if (!$this->isDataContainer($returnClass)) {
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

        $schema = match (true) {
            is_a($returnClass, CursorPaginatedDataCollection::class, allow_string: true)
                => $this->schemaFactory->envelope(PaginatorKind::Cursor, new OA\Items(['ref' => $ref])),
            is_a($returnClass, PaginatedDataCollection::class, allow_string: true)
                => $this->schemaFactory->envelope(PaginatorKind::LengthAware, new OA\Items(['ref' => $ref])),
            default => new OA\Schema([
                'type' => 'array',
                'items' => new OA\Items(['ref' => $ref]),
            ]),
        };

        return $this->response($schema);
    }

    private function isDataContainer(string $class): bool
    {
        return is_a($class, DataCollection::class, allow_string: true)
            || is_a($class, PaginatedDataCollection::class, allow_string: true)
            || is_a($class, CursorPaginatedDataCollection::class, allow_string: true);
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
