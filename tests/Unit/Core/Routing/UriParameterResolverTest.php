<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Radiergummi\OpenApi\Core\Routing\UriParameterResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('routing', 'openapi');

/**
 * A bound model whose constructor requires arguments — instantiating it with
 * `new $class()` would throw an `ArgumentCountError`.
 */
final class RoutableWithRequiredCtorArg implements UrlRoutable
{
    public function __construct(private readonly string $required) {}

    public function getRouteKey(): string
    {
        return $this->required;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field = null): ?self
    {
        return null;
    }
}

/** A bound model whose route key name resolution itself fails. */
final class RoutableWithThrowingKeyName implements UrlRoutable
{
    public function getRouteKey(): mixed
    {
        return null;
    }

    public function getRouteKeyName(): string
    {
        throw new RuntimeException('boom');
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field = null): ?self
    {
        return null;
    }
}

beforeEach(function (): void {
    $this->resolver = new UriParameterResolver(TypeResolver::create());
});

it('resolves a plain string parameter without constraints', function (): void {
    $param = reflectFunctionParameter(static function (string $project): void {}, 'project');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->name)->toBe('project')
        ->and($descriptor->optional)->toBeFalse()
        ->and($descriptor->whereConstraint)->toBeNull()
        ->and($descriptor->whereKind)->toBeNull()
        ->and($descriptor->modelClass)->toBeNull()
        ->and($descriptor->enumCases)->toBeNull();
});

it('resolves a nullable parameter as optional', function (): void {
    $param = reflectFunctionParameter(static function (?string $project): void {}, 'project');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->optional)->toBeTrue();
});

it('resolves a bound model whose constructor requires arguments', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithRequiredCtorArg $thing): void {},
        'thing',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelClass)->toBe(RoutableWithRequiredCtorArg::class)
        ->and($descriptor->routeKeyName)->toBe('slug');
});

it('degrades gracefully when route key name resolution throws', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithThrowingKeyName $thing): void {},
        'thing',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelClass)->toBeNull()
        ->and($descriptor->routeKeyName)->toBeNull();
});
