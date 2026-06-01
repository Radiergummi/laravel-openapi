<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;

use function spl_object_id;

/**
 * Per-walk cache for {@see ReflectionAttribute}s of arbitrary reflectors. Reads
 * `$reflector->getAttributes()` once per reflector and buckets the result by attribute FQCN — every
 * caller that asks for a specific attribute class then reads from the bucket rather than walking
 * the attribute list again.
 *
 * Sibling lint rules that all key off the same target attribute (e.g. the three Fractal rules that
 * read `#[FractalResponse]` off the same method, or the two resource rules that walk
 * `#[ResourceField]` on the same resource class) share a single bucket build through this cache.
 *
 * Construction of `ReflectionClass` from a class-string is also memoised, so sibling rules can pass
 * a class FQCN and share the same {@see ReflectionClass} instance.
 *
 * The cache is scoped to a single {@see LintContext} — created once per walk, discarded with
 * the context.
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
     * Same as {@see attributes()} but takes a class FQCN — the corresponding {@see ReflectionClass}
     * is built once and reused on subsequent lookups.
     *
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
        // Bucket entries indexed by $attribute hold `ReflectionAttribute<$attribute>` by
        // construction; PHPStan cannot follow the per-key narrowing through a single array, so the
        // covariance is asserted here.
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
