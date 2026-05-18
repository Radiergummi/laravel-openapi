<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Registry\ResolvedSchema;

uses()->group('openapi');

it('carries a component key and defaults to application/json', function (): void {
    $resolved = new ResolvedSchema('CreateProjectData');

    expect($resolved->componentKey)
        ->toBe('CreateProjectData')
        ->and($resolved->mediaType)->toBe(MediaType::Json);
});

it('accepts an explicit media type', function (): void {
    $resolved = new ResolvedSchema('UploadData', MediaType::MultipartFormData);

    expect($resolved->mediaType)->toBe(MediaType::MultipartFormData);
});
