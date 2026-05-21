<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Resolves a named parameter of a closure to its ReflectionParameter.
 */
function reflectFunctionParameter(Closure $fn, string $name): ReflectionParameter
{
    foreach ((new ReflectionFunction($fn))->getParameters() as $param) {
        if ($param->getName() === $name) {
            return $param;
        }
    }

    throw new RuntimeException("Parameter {$name} not found.");
}

/**
 * Runs the generator and returns the rendered OpenAPI document as a parsed array.
 *
 * @param array<int, callable(Radiergummi\OpenApi\Core\Routing\ActionDescriptor): bool> $filters
 *
 * @return array<string, mixed>
 */
function generateSpec(array $filters = []): array
{
    return Yaml::parse(app(OpenApiGenerator::class)->generate($filters)->toYaml());
}
