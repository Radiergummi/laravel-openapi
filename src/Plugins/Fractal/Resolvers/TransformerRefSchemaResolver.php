<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use ReflectionClass;
use ReflectionException;

use function class_exists;

/**
 * Resolves a Fractal transformer class to a `#/components/schemas/…` ref.
 *
 * Matches classes with a `#[TransformerField]` attribute, or `TransformerAbstract` subclasses
 * whose `transform()` body is statically readable. The subclass check uses FQCN strings, not a
 * direct `league/fractal` import.
 */
#[Scoped]
final readonly class TransformerRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromTransformer $schemaFromTransformer,
        private TransformerTransformReader $transformReader,
    ) {}

    /**
     * @throws ReflectionException
     */
    #[Override]
    public function resolveRef(string $class): ?string
    {
        if (!$this->canResolve($class)) {
            return null;
        }

        return $this->schemaFromTransformer->buildRef($class);
    }

    /**
     * @throws ReflectionException
     */
    #[Override]
    public function canResolve(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        if (new ReflectionClass($class)->getAttributes(TransformerField::class) !== []) {
            return true;
        }

        return $this->transformReader->isTransformerSubclass($class)
            && $this->transformReader->read($class) !== null;
    }
}
