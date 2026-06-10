<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fixture for the timestamp precedence (#250): an explicit `@property` tag for
 * `created_at` and an explicit cast for `updated_at` both win over the default.
 *
 * @property Carbon $created_at
 */
class OverriddenTimestampsArticle extends Model
{
    protected $guarded = [];

    protected $casts = [
        'updated_at' => 'date',
    ];
}
