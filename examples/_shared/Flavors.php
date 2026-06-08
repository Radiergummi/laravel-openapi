<?php

declare(strict_types=1);

namespace Examples\Shared;

use Examples\ApiResources\ExampleServiceProvider as ApiResources;
use Examples\Combined\ExampleServiceProvider as Combined;
use Examples\FormRequests\ExampleServiceProvider as FormRequests;
use Examples\Fractal\ExampleServiceProvider as Fractal;
use Examples\QueryBuilder\ExampleServiceProvider as QueryBuilder;
use Examples\SpatieData\ExampleServiceProvider as SpatieData;
use Examples\SwaggerPhp\ExampleServiceProvider as SwaggerPhp;
use Examples\Vanilla\ExampleServiceProvider as Vanilla;

/**
 * Single source of truth mapping flavor slug → flavor `ExampleServiceProvider`.
 *
 * Consumed by `examples/generate.php` (the CLI runner) and `tests/Feature/ExamplesTest.php`
 * (the verification suite). Adding a new flavor only needs to touch this file.
 */
final class Flavors
{
    /**
     * @return array<string, class-string>
     */
    public static function all(): array
    {
        return [
            'vanilla'       => Vanilla::class,
            'form-requests' => FormRequests::class,
            'spatie-data'   => SpatieData::class,
            'api-resources' => ApiResources::class,
            'fractal'       => Fractal::class,
            'query-builder' => QueryBuilder::class,
            'swagger-php'   => SwaggerPhp::class,
            'combined'      => Combined::class,
        ];
    }
}
