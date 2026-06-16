<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;

use function array_map;
use function class_exists;
use function count;
use function is_a;
use function sprintf;

/**
 * Resolves a Spatie Data return type into its `200 OK` response.
 *
 * Handles: a bare `Data` subclass (`$ref`), a `DataCollection` (array of `$ref`s, item class from
 * the `@return` generic), and a union (`oneOf`, collapsing to a bare `$ref` for a single member).
 * Paginated collections are deferred to `PaginatorResponseResolver`. Returns null when the return
 * type is not a Data class or non-paginating collection, or the collection generic is missing.
 */
#[Scoped]
final readonly class DataResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private DataRefSchemaResolver $refResolver,
        private ReturnTypeExtractor $returnTypeExtractor,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $returnType = $reflector->getReturnType();

        if ($returnType instanceof ReflectionUnionType) {
            return $this->resolveUnion($returnType);
        }

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $returnClass = $returnType->getName();

        // Paginated collections are claimed by PaginatorResponseResolver.
        if (PaginatorKind::fromClass($returnClass) !== null) {
            return null;
        }

        if (is_a($returnClass, Data::class, allow_string: true)) {
            /** @var class-string<Data> $returnClass */
            $ref = $this->refResolver->resolveRef($returnClass);

            if ($ref === null) {
                return null;
            }

            return $this->response(new OA\Schema(['ref' => $ref]));
        }

        if (!is_a($returnClass, DataCollection::class, allow_string: true)) {
            return null;
        }

        $itemClass = $this->returnTypeExtractor->genericArgument($reflector);

        if (
            $itemClass === null
            || !class_exists($itemClass)
            || !is_a($itemClass, Data::class, allow_string: true)
        ) {
            $this->logger->warning(
                sprintf(
                    'Route %s returns a Spatie %s but its item type is undeclared; '
                    . 'add a `@return %s<Item>` docblock.',
                    $descriptor->route->uri(),
                    $returnClass,
                    $returnClass,
                ),
            );

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

    /**
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function resolveUnion(ReflectionUnionType $union): ?OA\Response
    {
        $refs = [];

        foreach ($union->getTypes() as $member) {
            if (!$member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $class = $member->getName();

            if (!is_a($class, Data::class, allow_string: true)) {
                continue;
            }

            /** @var class-string<Data> $class */
            $ref = $this->refResolver->resolveRef($class);

            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        if ($refs === []) {
            return null;
        }

        if (count($refs) === 1) {
            return $this->response(new OA\Schema(['ref' => $refs[0]]));
        }

        return $this->response(new OA\Schema([
            'oneOf' => array_map(static fn(string $ref): OA\Schema => new OA\Schema(['ref' => $ref]), $refs),
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
