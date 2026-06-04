<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $secret
 * @property string $title
 */
class VisibleArticle extends Model
{
    protected $guarded = [];

    protected $visible = ['title', 'reading_time'];

    protected $appends = ['reading_time'];

    public function getReadingTimeAttribute(): int
    {
        return 5;
    }
}
