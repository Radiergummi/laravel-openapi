<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UlidArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UuidArticle;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

uses()->group('routing', 'openapi');

// String / nullable / enum parameter resolution is exercised by every feature
// test that uses a path parameter. This file is reduced to the defensive cases
// that depend on bound-model quirks no feature fixture can naturally exhibit.

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

it('carries the custom binding field onto the descriptor', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithRequiredCtorArg $post): void {},
        'post',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null, bindingField: 'slug');

    expect($descriptor->bindingField)->toBe('slug')
        ->and($descriptor->modelClass)->toBe(RoutableWithRequiredCtorArg::class);
});

it('leaves the binding field null when none is bound', function (): void {
    $param = reflectFunctionParameter(static function (string $id): void {}, 'id');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->bindingField)->toBeNull();
});

it('captures integer primary-key metadata for an int-keyed model', function (): void {
    $param = reflectFunctionParameter(static function (Article $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelPrimaryKey)->not->toBeNull()
        ->and($descriptor->modelPrimaryKey->name)->toBe('id')
        ->and($descriptor->modelPrimaryKey->type)->toBe('integer')
        ->and($descriptor->modelPrimaryKey->format)->toBeNull();
});

it('captures uuid primary-key metadata for a HasUuids model', function (): void {
    $param = reflectFunctionParameter(static function (UuidArticle $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelPrimaryKey)->not->toBeNull()
        ->and($descriptor->modelPrimaryKey->name)->toBe('id')
        ->and($descriptor->modelPrimaryKey->type)->toBe('string')
        ->and($descriptor->modelPrimaryKey->format)->toBe('uuid');
});

it('captures string primary-key metadata without a format for a HasUlids model', function (): void {
    $param = reflectFunctionParameter(static function (UlidArticle $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelPrimaryKey)->not->toBeNull()
        ->and($descriptor->modelPrimaryKey->name)->toBe('id')
        ->and($descriptor->modelPrimaryKey->type)->toBe('string')
        ->and($descriptor->modelPrimaryKey->format)->toBeNull();
});

it('leaves primary-key metadata null for a non-Eloquent routable', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithRequiredCtorArg $thing): void {},
        'thing',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelClass)->toBe(RoutableWithRequiredCtorArg::class)
        ->and($descriptor->modelPrimaryKey)->toBeNull();
});
