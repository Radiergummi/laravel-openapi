<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use ReflectionClass;

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

    public function resolveRef(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        if (new ReflectionClass($class)->getAttributes(TransformerField::class) === []) {
            return null;
        }

        return $this->schemaFromTransformer->buildRef($class);
    }
}
