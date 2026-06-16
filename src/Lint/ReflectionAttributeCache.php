<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;

use function spl_object_id;

/**
 * Per-walk cache for {@see ReflectionAttribute}s. Buckets `getAttributes()` results by FQCN so
 * sibling rules reading the same attribute on the same reflector pay for the call only once.
 * {@see ReflectionClass} construction is also memoised. Scoped to a single {@see LintContext}.
 */
final class ReflectionAttributeCache
{
    /**
     * @var array<int, array<class-string, list<ReflectionAttribute<object>>>>
     */
    private array $buckets = [];

    /**
     * @var array<class-string, ReflectionClass<object>>
     */
    private array $classReflectors = [];

    /**
     * @template T of object
     *
     * @param class-string    $class
     * @param class-string<T> $attribute
     *
     * @return list<ReflectionAttribute<T>>
     *
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection (false positive)
     */
    public function classAttributes(string $class, string $attribute): array
    {
        return $this->attributes($this->reflectionClass($class), $attribute);
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<object>|ReflectionFunctionAbstract $reflector
     * @param class-string<T>                                    $attribute
     *
     * @return list<ReflectionAttribute<T>>
     */
    public function attributes(
        ReflectionClass|ReflectionFunctionAbstract $reflector,
        string $attribute,
    ): array {
        // PHPStan cannot narrow per-key covariance through a single array; assert it here.
        /** @var list<ReflectionAttribute<T>> $attributes */
        $attributes = $this->bucketFor($reflector)[$attribute] ?? [];

        return $attributes;
    }

    /**
     * @param ReflectionClass<object>|ReflectionFunctionAbstract $reflector
     *
     * @return array<class-string, list<ReflectionAttribute<object>>>
     */
    private function bucketFor(ReflectionClass|ReflectionFunctionAbstract $reflector): array
    {
        $key = spl_object_id($reflector);

        if (!isset($this->buckets[$key])) {
            $bucket = [];

            foreach ($reflector->getAttributes() as $attribute) {
                $bucket[$attribute->getName()][] = $attribute;
            }

            $this->buckets[$key] = $bucket;
        }

        return $this->buckets[$key];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ReflectionClass<T>
     *
     * @noinspection PhpUnhandledExceptionInspection, PhpDocMissingThrowsInspection (false positive)
     */
    public function reflectionClass(string $class): ReflectionClass
    {
        /** @var ReflectionClass<T> $reflection */
        $reflection = $this->classReflectors[$class] ??= new ReflectionClass($class);

        return $reflection;
    }
}
