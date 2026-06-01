<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * Fixture {@see Data} class exercising `#[MapInputName]` resolution in {@see SchemaFromDataClass}
 * (OAPI-001).
 *
 * - `literalName` carries a literal wire-name attribute → schema must use
 *   `literal_name`.
 * - `mapperName` carries a NameMapper class → schema must use
 *   `mapper_name` (snake_case of the PHP property name).
 * - `unmapped` has no attribute → schema must use the PHP name.
 * - `optionalLiteral` mixes MapInputName with Spatie's Optional union to
 *   exercise the required[] list under mapping.
 */
final class MapInputNameFixtureData extends Data
{
    public function __construct(
        #[MapInputName('literal_name')]
        public string $literalName,
        #[MapInputName(SnakeCaseMapper::class)]
        public string $mapperName,
        public string $unmapped,
        #[MapInputName('optional_literal')]
        public string|Optional|null $optionalLiteral,
    ) {}
}
