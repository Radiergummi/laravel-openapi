<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Discriminator;

use Spatie\LaravelData\Data;

/**
 * Polymorphic base fixture for OAPI-027.
 * The generator must emit a oneOf + discriminator instead of a flat object.
 */
#[\Radiergummi\OpenApi\Core\Attributes\Discriminator(
    propertyName: 'type',
    mapping: [
        'circle'    => CircleData::class,
        'rectangle' => RectangleData::class,
    ],
)]
abstract class BaseShapeData extends Data {}
