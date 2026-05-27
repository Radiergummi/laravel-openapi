<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
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

    public function canResolve(string $class): bool
    {
        return is_a($class, Data::class, allow_string: true);
    }

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
}
