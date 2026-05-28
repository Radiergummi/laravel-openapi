<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Resolves a named parameter of a closure to its ReflectionParameter.
 */
function reflectFunctionParameter(Closure $fn, string $name): ReflectionParameter
{
    foreach (new ReflectionFunction($fn)->getParameters() as $param) {
        if ($param->getName() === $name) {
            return $param;
        }
    }

    throw new RuntimeException("Parameter {$name} not found.");
}

/**
 * Runs the generator against the named spec (or the default) and returns the rendered
 * OpenAPI document as a parsed array.
 *
 * @return array<string, mixed>
 */
function generateSpec(?string $specName = null, string $environment = 'testing'): array
{
    $registry = app(SpecRegistry::class);
    $spec = $specName === null ? $registry->default() : $registry->get($specName);

    $env = $environment !== 'testing' ? $environment : app()->environment();

    return Yaml::parse(app(OpenApiGenerator::class)->generate($spec, $env)->toYaml());
}
