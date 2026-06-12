<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for the timestamp default (#250): `$timestamps = false` must keep
 * `created_at`/`updated_at` out of the schema entirely.
 */
class UntimestampedArticle extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];
}
