<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Types;

use Illuminate\Support\Collection;
use LogicException;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionMethod;
use RuntimeException;
use stdClass;

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

    /** @return stdClass Non-generic return for resolver test. */
    public function plain(): stdClass
    {
        return new stdClass();
    }

    /** @throws LogicException|RuntimeException */
    public function throwsUnion(): void {}
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
