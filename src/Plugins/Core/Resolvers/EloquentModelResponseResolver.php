<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionNamedType;
use Throwable;

use function is_a;
use function sprintf;

/**
 * Resolves a direct Eloquent model return type into a `200 OK` response whose schema is a
 * `$ref` pointing at the model's component schema.
 *
 * Registered AFTER `PaginatorResponseResolver` so paginator return types are claimed first and
 * this resolver only sees bare `Model` subclass returns. Returns null for any non-Model return
 * type, deferring to the next resolver or the bare-200 fallback.
 *
 * Nullable model return types (e.g. `?Model`) are `ReflectionUnionType` and are not resolved.
 *
 * @internal
 */
final readonly class EloquentModelResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private EloquentModelToSchema $modelToSchema,
        private ComponentSchemaRegistry $registry,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            return $this->resolve($descriptor);
        } catch (Throwable $exception) {
            $this->logger->warning(
                sprintf(
                    'EloquentModelResponseResolver failed for route %s: %s',
                    $descriptor->route->uri(),
                    $exception->getMessage(),
                ),
            );

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

        $typeName = $returnType->getName();

        if (!is_a($typeName, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $typeName;

        $key = $this->modelToSchema->build($modelClass);
        $schema = new OA\Schema(['ref' => $this->registry->qualifyKey($key)]);

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]);
    }
}
