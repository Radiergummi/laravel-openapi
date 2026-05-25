<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Errors\ErrorResponse;

uses()->group('openapi');

it('defaults to empty content, headers, links, and null description', function (): void {
    $response = new ErrorResponse();

    expect($response->content)->toBe([]);
    expect($response->headers)->toBe([]);
    expect($response->links)->toBe([]);
    expect($response->description)->toBeNull();
});

it('bodyless() returns an instance with no body slots populated', function (): void {
    $response = ErrorResponse::bodyless();

    expect($response->content)->toBe([]);
    expect($response->headers)->toBe([]);
    expect($response->links)->toBe([]);
});

it('accepts named-argument construction', function (): void {
    $media = new OA\MediaType(['mediaType' => 'application/json']);
    $response = new ErrorResponse(
        content: [$media],
        description: 'Not found',
    );

    expect($response->content)->toBe([$media]);
    expect($response->description)->toBe('Not found');
    expect($response->headers)->toBe([]);
});
