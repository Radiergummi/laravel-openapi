<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Types;

use Illuminate\Support\Collection;
use LogicException;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Types\BrokenTypeContext;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Same-namespace value class referenced bare (no `use` import) in a generic below, exercising the
 * CollectionType-unwrap branch symfony/type-info takes for same-namespace identifiers.
 */
class SameNamespaceValue {}

/**
 * Fixture supplies the namespace + `use` context the resolver reads.
 */
class TypeNodeResolverFixture
{
    /** @return Collection<int, stdClass> */
    public function generic(): Collection
    {
        return new Collection();
    }

    /** @return Collection<int, SameNamespaceValue> */
    public function sameNamespaceGeneric(): Collection
    {
        return new Collection();
    }

    /** @return stdClass Non-generic return for resolver test. */
    public function plain(): stdClass
    {
        return new stdClass();
    }

    /** @return ?Collection<int, stdClass> */
    public function nullableGeneric(): ?Collection
    {
        return null;
    }

    /** @return null|Collection<int, stdClass> */
    public function unionNullableGeneric(): ?Collection
    {
        return null;
    }

    /** @return ?stdClass Nullable class for resolver tests. */
    public function nullableClass(): ?stdClass
    {
        return null;
    }

    /** @return null|stdClass Union-nullable class for resolver tests. */
    public function unionNullableClass(): ?stdClass
    {
        return null;
    }

    /** @return null|LogicException|stdClass Multi-class nullable union for resolver tests. */
    public function multiClassNullable(): stdClass|LogicException|null
    {
        return null;
    }

    /** @return LogicException|stdClass Multi-class union for resolver tests. */
    public function multiClass(): stdClass|LogicException
    {
        return new stdClass();
    }

    /** @return int Scalar return for resolver tests. */
    public function scalar(): int
    {
        return 0;
    }

    /** @throws LogicException|RuntimeException */
    public function throwsUnion(): void {}

    /**
     * @throws NotARealClassXyz @phpstan-ignore throws.notThrowable (fixture: intentionally unresolvable class to test written-name fallback in throwsClasses())
     */
    public function throwsUnresolved(): void {}
}

function makeResolverPair(): array
{
    return [DocBlockParser::create(), TypeNodeResolver::create()];
}

it('resolves the value class of a generic to an FQCN', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'generic');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($resolver->genericValueClass($type, $method))->toBe('stdClass');
});

it('resolves a same-namespace generic value class via the CollectionType branch', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'sameNamespaceGeneric');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($resolver->genericValueClass($type, $method))->toBe(SameNamespaceValue::class);
});

it('resolves the value class of a nullable generic', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'nullableGeneric');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($resolver->genericValueClass($type, $method))->toBe('stdClass');
});

it('resolves the value class of a generic in a union with null', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'unionNullableGeneric');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($resolver->genericValueClass($type, $method))->toBe('stdClass');
});

it('returns null when the return type is not a generic', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'plain');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($type)->not->toBeNull();
    expect($resolver->genericValueClass($type, $method))->toBeNull();
});

it('flattens a union @throws node into FQCNs in order', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'throwsUnion');
    $types = $parser->parse((string) $method->getDocComment())->throwsTypes();

    expect($resolver->throwsClasses($types[0], $method))
        ->toBe(['LogicException', 'RuntimeException']);
});

it('falls back to the written name when a @throws class cannot be resolved', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'throwsUnresolved');
    $types = $parser->parse((string) $method->getDocComment())->throwsTypes();

    expect($resolver->throwsClasses($types[0], $method))->toBe(['NotARealClassXyz']);
});

it('does not crash when the declaring class has annotations type-info cannot parse', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(BrokenTypeContext::class, 'boom');
    $types = $parser->parse((string) $method->getDocComment())->throwsTypes();

    // The class carries a malformed @phpstan-import-type that makes TypeContextFactory throw;
    // resolution must degrade to a context-free lookup rather than aborting the run.
    expect($resolver->throwsClasses($types[0], $method))->toBe(['RuntimeException']);
});

function returnTypeNode(string $method): array
{
    [$parser, $resolver] = makeResolverPair();
    $reflection = new ReflectionMethod(TypeNodeResolverFixture::class, $method);
    $type = $parser->parse((string) $reflection->getDocComment())->returnType();

    return [$type, $resolver, $reflection];
}

it('resolves a class name through a `?T` nullable wrapper', function (): void {
    [$type, $resolver, $reflection] = returnTypeNode('nullableClass');

    expect($resolver->resolveClassName($type, $reflection))->toBe('stdClass');
});

it('resolves a class name through a `T|null` union', function (): void {
    [$type, $resolver, $reflection] = returnTypeNode('unionNullableClass');

    expect($resolver->resolveClassName($type, $reflection))->toBe('stdClass');
});

it('returns null resolving a class name from a multi-class union', function (): void {
    [$type, $resolver, $reflection] = returnTypeNode('multiClassNullable');

    expect($resolver->resolveClassName($type, $reflection))->toBeNull();
});

it('returns null resolving a class name from a scalar', function (): void {
    [$type, $resolver, $reflection] = returnTypeNode('scalar');

    expect($resolver->resolveClassName($type, $reflection))->toBeNull();
});

it('reports nullability of `?T`, `T|null`, and a non-nullable union', function (): void {
    [$nullable, $resolver] = returnTypeNode('nullableClass');
    [$unionNull] = returnTypeNode('unionNullableClass');
    [$multi] = returnTypeNode('multiClass');

    expect($resolver->isNullable($nullable))->toBeTrue()
        ->and($resolver->isNullable($unionNull))->toBeTrue()
        ->and($resolver->isNullable($multi))->toBeFalse();
});

it('unwraps a `T|null` union to its inner type but leaves a multi-class union unchanged', function (): void {
    [$unionNull, $resolver] = returnTypeNode('unionNullableClass');
    [$multiNull] = returnTypeNode('multiClassNullable');

    // T|null collapses to T (a different node); A|B|null is not a simple nullable, returned as-is.
    expect($resolver->unwrapNullable($unionNull))->not->toBe($unionNull)
        ->and($resolver->unwrapNullable($multiNull))->toBe($multiNull);
});
