<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Support\Routing\WhereKind;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UlidArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\UuidArticle;
use Symfony\Component\TypeInfo\TypeIdentifier;
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

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->modelClass)->toBe(RoutableWithRequiredCtorArg::class)
        ->and($descriptor->modelBinding->key)->toBe('slug');
});

it('degrades gracefully when route key name resolution throws', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithThrowingKeyName $thing): void {},
        'thing',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelBinding)->toBeNull();
});

it('binds by the custom field when one is supplied', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithRequiredCtorArg $post): void {},
        'post',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null, bindingField: 'slug');

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->key)->toBe('slug')
        ->and($descriptor->modelBinding->modelClass)->toBe(RoutableWithRequiredCtorArg::class);
});

it('leaves the binding null for a non-routable parameter', function (): void {
    $param = reflectFunctionParameter(static function (string $id): void {}, 'id');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelBinding)->toBeNull();
});

it('types the binding as integer for an int-keyed model', function (): void {
    $param = reflectFunctionParameter(static function (Article $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->key)->toBe('id')
        ->and($descriptor->modelBinding->type)->toBe('integer')
        ->and($descriptor->modelBinding->format)->toBeNull();
});

it('types the binding as string+uuid for a HasUuids model', function (): void {
    $param = reflectFunctionParameter(static function (UuidArticle $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->key)->toBe('id')
        ->and($descriptor->modelBinding->type)->toBe('string')
        ->and($descriptor->modelBinding->format)->toBe('uuid');
});

it('types the binding as a bare string for a HasUlids model', function (): void {
    $param = reflectFunctionParameter(static function (UlidArticle $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->key)->toBe('id')
        ->and($descriptor->modelBinding->type)->toBe('string')
        ->and($descriptor->modelBinding->format)->toBeNull();
});

it('does not type the key when the route binds by a non-primary-key field', function (): void {
    // {article:slug} binds Article by `slug`, not its int primary key `id` — so the key carries
    // no type and the parameter keeps its PHP-derived string type. This decision lives in the
    // resolver: the extractor only ever applies a type the resolver has already vetted.
    $param = reflectFunctionParameter(static function (Article $article): void {}, 'article');

    $descriptor = $this->resolver->resolve($param, whereConstraint: null, bindingField: 'slug');

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->key)->toBe('slug')
        ->and($descriptor->modelBinding->type)->toBeNull()
        ->and($descriptor->modelBinding->format)->toBeNull();
});

it('leaves the key type null for a non-Eloquent routable', function (): void {
    $param = reflectFunctionParameter(
        static function (RoutableWithRequiredCtorArg $thing): void {},
        'thing',
    );

    $descriptor = $this->resolver->resolve($param, whereConstraint: null);

    expect($descriptor->modelBinding)->not->toBeNull()
        ->and($descriptor->modelBinding->modelClass)->toBe(RoutableWithRequiredCtorArg::class)
        ->and($descriptor->modelBinding->type)->toBeNull();
});

// region resolveUnsignatured

it('synthesizes a bare string descriptor for an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured('team', whereConstraint: null);

    expect($descriptor->name)->toBe('team')
        ->and($descriptor->type->getTypeIdentifier())->toBe(TypeIdentifier::STRING)
        ->and($descriptor->optional)->toBeFalse()
        ->and($descriptor->whereConstraint)->toBeNull()
        ->and($descriptor->whereKind)->toBeNull()
        ->and($descriptor->enumCases)->toBeNull()
        ->and($descriptor->modelBinding)->toBeNull();
});

it('classifies a uuid where on an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured(
        'id',
        whereConstraint: '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}',
    );

    expect($descriptor->whereKind)->toBe(WhereKind::Uuid);
});

it('classifies a number where on an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured('id', whereConstraint: '[0-9]+');

    expect($descriptor->whereKind)->toBe(WhereKind::Number);
});

it('classifies a literal-alternation where on an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured('status', whereConstraint: 'draft|published');

    expect($descriptor->whereKind)->toBe(WhereKind::In);
});

it('classifies a custom regex where on an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured('slug', whereConstraint: '[a-z-]+');

    expect($descriptor->whereKind)->toBe(WhereKind::Custom)
        ->and($descriptor->whereConstraint)->toBe('[a-z-]+');
});

it('surfaces a whereIn alternation as enum cases for a signatured string param', function (): void {
    $param = reflectFunctionParameter(static function (string $status): void {}, 'status');

    $descriptor = $this->resolver->resolve($param, whereConstraint: 'draft|published');

    expect($descriptor->whereKind)->toBe(WhereKind::In)
        ->and($descriptor->enumCases)->toBe(['draft', 'published']);
});

it('does not attach a string whereIn enum to a signatured int param', function (): void {
    // `whereIn('id', [1, 2, 3])` writes the literal-alternation regex `1|2|3`, which classifies as
    // WhereKind::In. Its alternatives are strings, so they must not land as enum values on the
    // integer schema (a value/type mismatch); the param stays a bare {type: integer}.
    $param = reflectFunctionParameter(static function (int $id): void {}, 'id');

    $descriptor = $this->resolver->resolve($param, whereConstraint: '1|2|3');

    expect($descriptor->whereKind)->toBe(WhereKind::In)
        ->and($descriptor->enumCases)->toBeNull();
});

it('never recovers a model binding for an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured('article', whereConstraint: null, bindingField: 'slug');

    expect($descriptor->modelBinding)->toBeNull()
        ->and($descriptor->enumCases)->toBeNull();
});

it('propagates the optional flag for an unsignatured placeholder', function (): void {
    $descriptor = $this->resolver->resolveUnsignatured('opt', whereConstraint: null, optional: true);

    expect($descriptor->optional)->toBeTrue()
        ->and($descriptor->type->getTypeIdentifier())->toBe(TypeIdentifier::STRING);
});

// endregion
