<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares attributes as statically-typed public properties. `$slug` carries no metadata, so only
 * the public-property fallback can type it; `$legacyCode` is also cast, so both readers answer and
 * disagree, which is the only shape that makes their precedence observable.
 */
class TypedPropertyArticle extends Model
{
    public string $slug = '';

    public string $legacyCode = '';

    protected $guarded = [];

    protected $casts = [
        'legacyCode' => 'integer',
    ];
}
