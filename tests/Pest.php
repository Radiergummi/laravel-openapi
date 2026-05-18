<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Tests\TestCase;

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
