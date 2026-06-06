<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

uses()->group('routing', 'openapi');

/**
 * @param list<string> $verbs
 */
function descriptorForClosureRoute(string $uri, Closure $action, array $verbs = ['GET']): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route($verbs, $uri, $action),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );
}

it('detects a custom-key bound parameter', function (): void {
    $descriptor = descriptorForClosureRoute(
        'posts/{post:slug}',
        static fn(string $post): null => null,
    );

    $names = array_map(static fn($p): string => $p->getName(), $descriptor->uriParameters);

    expect($names)->toBe(['post']);
});

it('detects both parameters of a scoped-nested custom-key binding', function (): void {
    $descriptor = descriptorForClosureRoute(
        'users/{user}/posts/{post:slug}',
        static fn(string $user, string $post): null => null,
    );

    $names = array_map(static fn($p): string => $p->getName(), $descriptor->uriParameters);

    expect($names)->toBe(['user', 'post']);
});

it('detects an optional custom-key bound parameter', function (): void {
    $descriptor = descriptorForClosureRoute(
        'posts/{post:slug?}',
        static fn(?string $post = null): null => null,
    );

    $names = array_map(static fn($p): string => $p->getName(), $descriptor->uriParameters);

    expect($names)->toBe(['post']);
});

it('still detects a plain binding and an optional segment (regression guard)', function (): void {
    $plain = descriptorForClosureRoute('posts/{post}', static fn(string $post): null => null);
    $optional = descriptorForClosureRoute('tags/{tag?}', static fn(?string $tag = null): null => null);

    expect(array_map(static fn($p): string => $p->getName(), $plain->uriParameters))->toBe(['post'])
        ->and(array_map(static fn($p): string => $p->getName(), $optional->uriParameters))->toBe(['tag']);
});

it('exposes the custom binding field via bindingFieldFor()', function (): void {
    $descriptor = descriptorForClosureRoute(
        'posts/{post:slug}',
        static fn(string $post): null => null,
    );

    expect($descriptor->bindingFieldFor('post'))->toBe('slug');
});

it('returns null from bindingFieldFor() for a plain binding', function (): void {
    $descriptor = descriptorForClosureRoute(
        'posts/{post}',
        static fn(string $post): null => null,
    );

    expect($descriptor->bindingFieldFor('post'))->toBeNull();
});
