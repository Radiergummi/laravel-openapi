<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for the long-lived app whose model has outgrown its migration: every docblock tag below
 * contradicts the column the migration declares, so the migration contributes keywords that cannot
 * apply to the resolved type. `score` and `slug` agree with their columns and guard against
 * over-pruning.
 *
 * @property int          $code
 * @property int          $device
 * @property string       $id
 * @property int          $score
 * @property null|string  $slug
 * @property list<string> $tags
 */
class ConflictedMetadataArticle extends Model
{
    // The key has since migrated to a ULID, which is what leaves the increments() column behind.
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'conflicted_articles';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
    ];
}
