<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for the long-lived app whose model has outgrown its migration: every docblock tag below
 * contradicts the column the migration declares, so the migration contributes keywords that cannot
 * apply to the resolved type. `score` and `slug` agree with their columns and guard against
 * over-pruning; `rate` is the one column whose migration contributes two inapplicable keywords at
 * once, since an `unsignedDecimal` head yields `minimum` alongside the scale-derived `multipleOf`
 * while the `decimal:2` cast resolves the property to a string.
 *
 * The value-level pairs sit alongside them: `status`/`flags` contradict their `enum` column and
 * `published_on`/`reference` contradict their date and uuid `format`, while `state`, `tier`, `token`
 * and the untyped `mode` are the compatible counterparts that must survive.
 *
 * @property int          $code
 * @property int          $device
 * @property list<string> $flags
 * @property string       $id
 * @property mixed        $mode
 * @property int          $published_on
 * @property int          $reference
 * @property int          $score
 * @property null|string  $slug
 * @property string       $state
 * @property int          $status
 * @property list<string> $tags
 * @property null|string  $tier
 * @property string       $token
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
        'rate' => 'decimal:2',
        'tags' => 'array',
    ];
}
