<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $title
 * @property string $uuid
 */
class CustomPrimaryKeyArticle extends Model
{
    protected $primaryKey = 'uuid';

    public $timestamps = false;

    protected $guarded = [];
}
