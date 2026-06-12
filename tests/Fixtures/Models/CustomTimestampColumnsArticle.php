<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for the timestamp default (#250): a renamed created-at column and a
 * disabled updated-at column via the framework constants.
 */
class CustomTimestampColumnsArticle extends Model
{
    public const CREATED_AT = 'creation_date';

    public const UPDATED_AT = null;

    protected $guarded = [];
}
