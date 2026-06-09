<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use ReflectionClass;
use ReflectionException;

use function class_exists;

/**
 * Resolves the source file declaring a class, for fixers that locate an authored annotation to
 * remove. The class name originates from finding context, so it is untrusted; a non-existent or
 * unreflectable name yields null rather than throwing.
 *
 * @internal
 */
trait ResolvesDeclaringFile
{
    private function fileFor(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        try {
            $file = new ReflectionClass($class)->getFileName();
        } catch (ReflectionException) {
            return null;
        }

        return $file ?: null;
    }
}
