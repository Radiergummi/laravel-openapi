<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exercises array-shape `@property` resolution: a sealed shape, an optional key, a nested shape,
 * and a list of shapes.
 *
 * @property array{street: string, unit?: string} $address
 * @property array{lat: float, lng: float}        $coordinates
 * @property array{meta: array{source: string}}   $envelope
 * @property list<array{id: int, label: string}>  $tags
 */
class ShapedArticle extends Model
{
    protected $guarded = [];
}
