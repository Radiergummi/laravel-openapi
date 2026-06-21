<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $deleted_at
 * @property string $id
 * @property string $title
 */
class SoftDeletingArticle extends Model
{
    use SoftDeletes;

    protected $guarded = [];
}
