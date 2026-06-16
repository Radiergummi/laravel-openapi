<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

uses()->group('openapi', 'routing');

it('emits a valid path template and surfaces the custom binding field', function (): void {
    Route::get('/posts/{post:slug}', static fn(Article $post): array => []);

    $spec = generateSpec();

    expect($spec['paths'])
        ->toHaveKey('/posts/{post}')
        ->and($spec['paths'])->not->toHaveKey('/posts/{post:slug}');

    $post = collect($spec['paths']['/posts/{post}']['get']['parameters'])
        ->firstWhere('name', 'post');

    expect($post)->not
        ->toBeNull()
        ->and($post['in'])->toBe('path')
        ->and($post['required'])->toBeTrue()
        ->and($post['description'])->toBe('Bound by slug of Article.');
});

it('keeps both parameters for a scoped-nested custom-key binding', function (): void {
    Route::get('/users/{user}/posts/{post:slug}', static fn(User $user, Article $post): array => []);

    $spec = generateSpec();

    expect($spec['paths'])->toHaveKey('/users/{user}/posts/{post}');

    $names = collect($spec['paths']['/users/{user}/posts/{post}']['get']['parameters'])
        ->pluck('name')
        ->all();

    expect($names)->toContain('user')->toContain('post');
});

it('strips the field from an optional custom-key segment and never emits a colon', function (): void {
    Route::get('/posts/{post:slug?}', static fn(?Article $post = null): array => []);
    Route::get('/plain/{post}', static fn(Article $post): array => []);

    $spec = generateSpec();

    expect($spec['paths'])
        ->toHaveKey('/posts/{post?}')
        ->and($spec['paths'])->toHaveKey('/plain/{post}');

    foreach (array_keys($spec['paths']) as $path) {
        expect($path)->not->toContain(':');
    }
});
