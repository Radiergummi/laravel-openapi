<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Config\ConfigDiffer;

uses()->group('generator', 'openapi');

it('reports no diffs for identical arrays', function (): void {
    $diffs = ConfigDiffer::diff(
        ['a' => 1, 'b' => ['c' => 2]],
        ['a' => 1, 'b' => ['c' => 2]],
    );

    expect($diffs)->toBe([]);
});

it('reports an added key with its default value', function (): void {
    $diffs = ConfigDiffer::diff(
        ['a' => 1, 'b' => 2],
        ['a' => 1],
    );

    expect($diffs)->toBe([
        ['kind' => 'added', 'path' => 'b', 'default' => 2],
    ]);
});

it('reports a removed key from user config', function (): void {
    $diffs = ConfigDiffer::diff(
        ['a' => 1],
        ['a' => 1, 'b' => 2],
    );

    expect($diffs)->toBe([
        ['kind' => 'removed', 'path' => 'b', 'user' => 2],
    ]);
});

it('reports changed default values', function (): void {
    $diffs = ConfigDiffer::diff(
        ['error_envelope' => 'rfc7807'],
        ['error_envelope' => 'none'],
    );

    expect($diffs)->toBe([
        ['kind' => 'changed', 'path' => 'error_envelope', 'default' => 'rfc7807', 'user' => 'none'],
    ]);
});

it('walks nested arrays and joins paths with a dot', function (): void {
    $diffs = ConfigDiffer::diff(
        ['examples' => ['synthesise' => true, 'faker_seed' => 1234]],
        ['examples' => ['synthesise' => true]],
    );

    expect($diffs)->toBe([
        ['kind' => 'added', 'path' => 'examples.faker_seed', 'default' => 1234],
    ]);
});

it('does not descend into non-associative arrays (list values)', function (): void {
    $diffs = ConfigDiffer::diff(
        ['plugins' => ['PluginA', 'PluginB']],
        ['plugins' => ['PluginA']],
    );

    expect($diffs)->toBe([
        ['kind' => 'changed', 'path' => 'plugins', 'default' => ['PluginA', 'PluginB'], 'user' => ['PluginA']],
    ]);
});
