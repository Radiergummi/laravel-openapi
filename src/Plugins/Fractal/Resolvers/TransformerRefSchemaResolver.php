<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use ReflectionClass;
use ReflectionException;

use function class_exists;

/**
 * Resolves a Fractal transformer class to a `#/components/schemas/…` ref. A
 * "transformer" is any class carrying at least one `#[TransformerField]`
 * attribute — the plugin never references `league/fractal` directly.
 */
#[Scoped]
final readonly class TransformerRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromTransformer $schemaFromTransformer,
    ) {}

    /**
     * @throws ReflectionException
     */
    public function resolveRef(string $class): ?string
    {
        if (!$this->canResolve($class)) {
            return null;
        }

        return $this->schemaFromTransformer->buildRef($class);
    }

    public function canResolve(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return new ReflectionClass($class)->getAttributes(TransformerField::class) !== [];
    }
}
