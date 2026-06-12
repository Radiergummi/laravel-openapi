<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;

uses()->group('openapi');

it('returns the field-set for an exact route-name match', function (): void {
    $matcher = new OverrideMatcher([
        'users.show' => ['operationId' => 'getCurrentUser', 'deprecated' => true],
    ]);

    expect($matcher->fieldsFor('users.show', 'api/users/{user}'))
        ->toBe(['operationId' => 'getCurrentUser', 'deprecated' => true]);
});

it('matches a URI glob against the operation uri', function (): void {
    $matcher = new OverrideMatcher([
        'api/v1/legacy/*' => ['x-internal' => true],
    ]);

    expect($matcher->fieldsFor(null, 'api/v1/legacy/users'))
        ->toBe(['x-internal' => true]);
});

it('treats * as matching across slashes', function (): void {
    $matcher = new OverrideMatcher([
        'api/*' => ['deprecated' => true],
    ]);

    expect($matcher->fieldsFor(null, 'api/v1/deep/nested/path'))
        ->toBe(['deprecated' => true]);
});

it('merges per-field across multiple matches with the most specific winning', function (): void {
    $matcher = new OverrideMatcher([
        'api/v1/legacy/*'     => ['x-internal' => true],
        'api/v1/legacy/users' => ['deprecated' => true],
        'legacy.users'        => ['tags' => ['Legacy']],
    ]);

    expect($matcher->fieldsFor('legacy.users', 'api/v1/legacy/users'))
        ->toBe([
            'x-internal' => true,
            'deprecated' => true,
            'tags'       => ['Legacy'],
        ]);
});

it('lets the more specific glob override a shared field', function (): void {
    $matcher = new OverrideMatcher([
        'api/*'        => ['tags' => ['Broad']],
        'api/users/*'  => ['tags' => ['Narrow']],
    ]);

    expect($matcher->fieldsFor(null, 'api/users/42')['tags'])->toBe(['Narrow']);
});

it('breaks specificity ties by declaration order, later key winning', function (): void {
    $matcher = new OverrideMatcher([
        'api/a/*' => ['tags' => ['First']],
        'api/b/*' => ['tags' => ['Second']],
    ]);

    expect($matcher->fieldsFor(null, 'api/b/x')['tags'])->toBe(['Second']);
});

it('lets the exact route-name key win over any glob on the same field', function (): void {
    $matcher = new OverrideMatcher([
        'api/users/*' => ['tags' => ['FromGlob']],
        'users.show'  => ['tags' => ['FromName']],
    ]);

    expect($matcher->fieldsFor('users.show', 'api/users/42')['tags'])
        ->toBe(['FromName']);
});

it('passes through arbitrary x-* extensions', function (): void {
    $matcher = new OverrideMatcher([
        'users.show' => ['x-rate-limit' => ['window' => 60, 'max' => 100]],
    ]);

    expect($matcher->fieldsFor('users.show', 'api/users'))
        ->toBe(['x-rate-limit' => ['window' => 60, 'max' => 100]]);
});

it('drops non-allowlisted fields', function (): void {
    $matcher = new OverrideMatcher([
        'users.show' => [
            'summary'    => 'ok',
            'responses'  => ['200' => []], // not allowlisted
            'operatonId' => 'typo',        // not allowlisted (typo)
        ],
    ]);

    expect($matcher->fieldsFor('users.show', 'api/users'))
        ->toBe(['summary' => 'ok']);
});

it('returns an empty array when nothing matches', function (): void {
    $matcher = new OverrideMatcher([
        'users.show' => ['summary' => 'ok'],
    ]);

    expect($matcher->fieldsFor('posts.index', 'api/posts'))->toBe([]);
});

it('skips a matching block whose fields are all non-allowlisted', function (): void {
    $matcher = new OverrideMatcher([
        'users.show' => ['responses' => [], 'parameters' => []], // filters to empty
    ]);

    expect($matcher->fieldsFor('users.show', 'api/users'))->toBe([]);
});

it('reports hasOverrides only when overrides are configured', function (): void {
    expect(new OverrideMatcher([])->hasOverrides)->toBeFalse()
        ->and(new OverrideMatcher(['users.show' => ['summary' => 'ok']])->hasOverrides)->toBeTrue();
});

it('reports keys that match no route name and no uri as unused', function (): void {
    $matcher = new OverrideMatcher([
        'users.show'    => ['summary' => 'ok'],     // matches by name
        'api/posts/*'   => ['deprecated' => true],  // matches by uri glob
        'legacy.foo'    => ['summary' => 'x'],       // matches nothing
        'api/missing/*' => ['summary' => 'x'],       // matches nothing
    ]);

    $routes = [
        ['name' => 'users.show', 'uri' => 'api/users/{user}'],
        ['name' => 'posts.index', 'uri' => 'api/posts/list'],
    ];

    expect($matcher->unusedKeys($routes))->toBe(['legacy.foo', 'api/missing/*']);
});

it('treats a key equal to an existing route name as used even if its glob form matches nothing', function (): void {
    $matcher = new OverrideMatcher([
        'users.show' => ['summary' => 'ok'],
    ]);

    $routes = [
        ['name' => 'users.show', 'uri' => 'api/users/{user}'],
    ];

    expect($matcher->unusedKeys($routes))->toBe([]);
});
