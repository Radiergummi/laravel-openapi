<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Carries both a `datetime` and a `date` cast alongside the framework timestamps, so a resource
 * can format each of them.
 *
 * @property      string       $id
 * @property      Carbon       $published_at
 * @property      Carbon       $release_date The day the article goes on sale.
 * @property-read DatedArticle $parent
 */
class DatedArticle extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'release_date' => 'date',
        'summary' => 'string',
    ];
}
