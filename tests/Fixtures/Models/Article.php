<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;

/**
 * @property      string        $id
 * @property      string        $internal_notes
 * @property      Carbon        $published_at
 * @property      ArticleStatus $status
 * @property      ?string       $subtitle
 * @property      list<string>  $tags
 * @property      string        $title
 * @property-read Author        $author
 * @property-read ?Author       $editor
 */
class Article extends Model
{
    protected $guarded = [];

    protected $hidden = ['internal_notes'];

    protected $appends = ['reading_time'];

    protected $casts = [
        'published_at' => 'datetime',
        'status' => ArticleStatus::class,
        'tags' => 'array',
    ];

    /**
     * @return BelongsTo<Author, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function getReadingTimeAttribute(): int
    {
        return 5;
    }
}
