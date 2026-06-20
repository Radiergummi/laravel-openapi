<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\DescribedStatus;

/**
 * @property      string          $email        The primary contact email.
 * @property      int             $login_count  Times the user has signed in.
 * @property      string          $name
 * @property      Carbon          $published_at When the article went live.
 * @property      DescribedStatus $status       A described status tag.
 * @property      string          $title        Surrounded by spaces.
 * @property-read string          $slug         URL-safe identifier.
 */
class DescribedArticle extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'published_at' => 'datetime',
        'status' => DescribedStatus::class,
    ];
}
