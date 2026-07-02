<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\DataReturnExpressionReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;
use Radiergummi\OpenApi\Support\Routing\GenericContainerReturnType;
use Radiergummi\OpenApi\Support\Routing\ReturnContainer;
use Radiergummi\OpenApi\Support\Routing\ReturnShape;
use Radiergummi\OpenApi\Support\Routing\ReturnShapeResolver;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type\ObjectType;

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
 * A generic-container return type (`Collection`, `array`, …) falls back to the return-expression
 * body scan for a literal `DataClass::collect(...)` / `new DataClass(...)` factory. Paginated
 * collections are deferred to `PaginatorResponseResolver`. Returns null when no Data shape resolves.
 */
#[Scoped]
final readonly class DataResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private DataRefSchemaResolver $refResolver,
        private ReturnTypeExtractor $returnTypeExtractor,
        private ReturnShapeResolver $shapeResolver,
        private DataReturnExpressionReader $returnExpressionReader,
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

        $shape = $this->shapeResolver->describe($reflector);

        // A union of Data classes → oneOf (a bare $ref for a single member), nullable when the
        // union includes a null member.
        if ($shape->unionMembers !== []) {
            return $this->resolveUnion($shape);
        }

        // Paginated collections are claimed by PaginatorResponseResolver.
        if ($shape->container === ReturnContainer::Paginated) {
            return null;
        }

        $returnType = $reflector->getReturnType();

        if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin()) {
            $returnClass = $returnType->getName();

            if (is_a($returnClass, Data::class, allow_string: true)) {
                /** @var class-string<Data> $returnClass */
                $ref = $this->refResolver->resolveRef($returnClass);

                if ($ref === null) {
                    return null;
                }

                return $this->response($this->nullableWrap(new OA\Schema(['ref' => $ref]), $shape->nullable));
            }

            if (is_a($returnClass, DataCollection::class, allow_string: true)) {
                return $this->resolveDataCollection($reflector, $descriptor, $returnClass);
            }
        }

        // A generic container (`Collection`, `array`, …) carries no item type; the body's Data
        // factory is the only evidence. Degrades silently when no literal factory is matched.
        if (!GenericContainerReturnType::matches($returnType) || $descriptor->method === null) {
            return null;
        }

        $target = $this->returnExpressionReader->read($descriptor->method);

        if ($target === null) {
            return null;
        }

        $ref = $this->refResolver->resolveRef($target->dataClass);

        if ($ref === null) {
            return null;
        }

        return $target->isCollection
            ? $this->response(new OA\Schema([
                'type' => 'array',
                'items' => new OA\Items(['ref' => $ref]),
            ]))
            : $this->response(new OA\Schema(['ref' => $ref]));
    }

    /**
     * Array of `$ref`s for a typed `DataCollection`, with the item class from the `@return` generic.
     * Warns and returns null when the generic is absent (the item shape is undeclared).
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function resolveDataCollection(
        ReflectionFunctionAbstract $reflector,
        ActionDescriptor $descriptor,
        string $returnClass,
    ): ?OA\Response {
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
    private function resolveUnion(ReturnShape $shape): ?OA\Response
    {
        $refs = [];

        foreach ($shape->unionMembers as $member) {
            if (!$member instanceof ObjectType) {
                continue;
            }

            $class = $member->getClassName();

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

        $schema = count($refs) === 1
            ? new OA\Schema(['ref' => $refs[0]])
            : new OA\Schema([
                'oneOf' => array_map(static fn(string $ref): OA\Schema => new OA\Schema(['ref' => $ref]), $refs),
            ]);

        return $this->response($this->nullableWrap($schema, $shape->nullable));
    }

    /** Wraps the schema in the OAS 3.1 nullable idiom when the return type admits null. */
    private function nullableWrap(OA\Schema $schema, bool $nullable): OA\Schema
    {
        return $nullable ? NullableSchema::wrap($schema) : $schema;
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
