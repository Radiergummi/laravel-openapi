<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Discriminator;

use Radiergummi\OpenApi\Attributes\Discriminator;
use Spatie\LaravelData\Data;

/**
 * Polymorphic base fixture for OAPI-027.
 * The generator must emit a oneOf + discriminator instead of a flat object.
 */
#[Discriminator(
    propertyName: 'type',
    mapping: [
        'circle'    => CircleData::class,
        'rectangle' => RectangleData::class,
    ],
)]
abstract class BaseShapeData extends Data {}
