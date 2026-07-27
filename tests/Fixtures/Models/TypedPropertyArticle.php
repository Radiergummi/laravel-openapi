<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares an attribute as a statically-typed public property instead of through `$casts` or a
 * `@property` tag, so the model metadata reader has nothing to say about it and only the
 * public-property fallback can type it.
 */
class TypedPropertyArticle extends Model
{
    public string $slug = '';

    protected $guarded = [];
}
