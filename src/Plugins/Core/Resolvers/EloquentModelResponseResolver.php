<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionException;
use ReflectionNamedType;

use function class_exists;
use function is_a;

/**
 * Resolves a direct Eloquent model return (or typed Collection of models) into a 200 response.
 * Registered after `PaginatorResponseResolver` so paginator returns are claimed first.
 * Nullable models are accepted; the nullable modifier is not reflected in the schema.
 *
 * @internal
 */
final readonly class EloquentModelResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private EloquentModelToSchema $modelToSchema,
        private ComponentSchemaRegistry $registry,
        private ReturnTypeExtractor $returnTypeExtractor,
    ) {}

    /**
     * @throws ReflectionException
     */
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

        $typeName = $returnType->getName();

        if (is_a($typeName, Model::class, true)) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $typeName;

            return $this->jsonResponse(new OA\Schema([
                'ref' => $this->refKey($modelClass),
            ]));
        }

        if (is_a($typeName, Collection::class, true)) {
            $itemClass = $this->returnTypeExtractor->genericArgument($reflector);

            if (
                $itemClass === null
                || !class_exists($itemClass)
                || !is_a($itemClass, Model::class, true)
            ) {
                return null;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $itemClass;

            $items = new OA\Items(['ref' => $this->refKey($modelClass)]);

            return $this->jsonResponse(new OA\Schema([
                'type' => 'array',
                'items' => $items,
            ]));
        }

        return null;
    }

    private function jsonResponse(OA\Schema $schema): PrimaryResponse
    {
        return PrimaryResponse::of(new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]));
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function refKey(string $modelClass): string
    {
        return $this->registry->qualifyKey($this->modelToSchema->build($modelClass));
    }
}
