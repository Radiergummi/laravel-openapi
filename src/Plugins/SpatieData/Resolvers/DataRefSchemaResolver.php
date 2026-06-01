<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\SchemaFromDataClass;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionException;
use RuntimeException;
use Spatie\LaravelData\Data;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;

use function is_a;

/**
 * Resolves a Spatie {@see Data} subclass to a `#/components/schemas/…` ref string, registering the
 * component schema via {@see SchemaFromDataClass} if not already present.
 */
#[Scoped]
final readonly class DataRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromDataClass $schemaFromDataClass,
        private ComponentSchemaRegistry $schemaRegistry,
    ) {}

    /**
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    public function resolveRef(string $class): ?string
    {
        if (!$this->canResolve($class)) {
            return null;
        }

        /** @var class-string<Data> $class */
        $key = $this->schemaFromDataClass->build($class);

        return $this->schemaRegistry->qualifyKey($key);
    }

    public function canResolve(string $class): bool
    {
        return is_a($class, Data::class, allow_string: true);
    }
}
