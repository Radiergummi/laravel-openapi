<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionNamedType;

use function class_exists;
use function is_a;

/**
 * Resolves a direct Eloquent model return type — or a typed Collection of models — into a
 * `200 OK` response whose schema is a `$ref` (single model) or an array of `$ref` items
 * (collection).
 *
 * Registered AFTER `PaginatorResponseResolver` so paginator return types are claimed first and
 * this resolver only sees bare `Model` subclass or `Collection<*, Model>` returns. Returns null
 * for any non-Model return type, deferring to the next resolver or the bare-200 fallback.
 *
 * A nullable model return (`?Model`) is a `ReflectionNamedType` with allowsNull(); it is
 * accepted and the nullable modifier is not reflected in the emitted schema, consistent
 * with the other primary-response resolvers.
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

        $typeName = $returnType->getName();

        if (is_a($typeName, Model::class, true)) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $typeName;

            return $this->jsonResponse(new OA\Schema(['ref' => $this->refKey($modelClass)]));
        }

        if (is_a($typeName, Collection::class, true)) {
            $itemClass = $this->returnTypeExtractor->genericArgument($reflector);

            if ($itemClass === null || !class_exists($itemClass) || !is_a($itemClass, Model::class, true)) {
                return null;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $itemClass;

            $items = new OA\Items(['ref' => $this->refKey($modelClass)]);

            return $this->jsonResponse(new OA\Schema(['type' => 'array', 'items' => $items]));
        }

        return null;
    }

    /**
     * Builds (once, cycle-guarded) the model's component schema and returns the qualified `$ref`
     * key pointing at it — shared by the single-model and collection-item paths.
     *
     * @param class-string<Model> $modelClass
     */
    private function refKey(string $modelClass): string
    {
        return $this->registry->qualifyKey($this->modelToSchema->build($modelClass));
    }

    private function jsonResponse(OA\Schema $schema): OA\Response
    {
        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]);
    }
}
