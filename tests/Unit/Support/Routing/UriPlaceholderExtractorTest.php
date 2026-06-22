<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Routing\UriPlaceholderExtractor;

uses()->group('openapi', 'routing');

it('extracts placeholders as [bareName, optional] pairs', function (string $uri, array $expected): void {
    expect(UriPlaceholderExtractor::extract($uri))->toBe($expected);
})->with([
    'plain'                        => ['/users/{id}', [['id', false]]],
    'optional'                     => ['/users/{id?}', [['id', true]]],
    'operator prefix'              => ['/files/{+path}', [['path', false]]],
    'custom key'                   => ['/members/{member:slug}', [['member', false]]],
    'custom key + optional'        => ['/members/{member:slug?}', [['member', true]]],
    'operator prefix + custom key' => ['/files/{+path:disk}', [['path', false]]],
    'no placeholders'              => ['/users', []],
    'multiple preserve order'      => [
        '/users/{userId}/posts/{postId}',
        [['userId', false], ['postId', false]],
    ],
]);
