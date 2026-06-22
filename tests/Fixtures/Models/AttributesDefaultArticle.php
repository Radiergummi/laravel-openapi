<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;

/**
 * Maps onto the `posts` fixture migration. Declares a static `$attributes` array so the Tier-0
 * default fill is observable: `summary`/`priority` take their default from `$attributes`,
 * `archived_at` carries an explicit null default, `state` has a migration `->default()` that
 * outranks its `$attributes` entry, and `status` is an enum-cast `$ref` whose `$attributes` entry
 * must not graft a stray default. `name` has no `$attributes` entry and stays default-free.
 *
 * @property string $archived_at
 * @property string $name
 * @property int    $priority
 * @property string $state
 * @property string $summary
 */
class AttributesDefaultArticle extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    protected $casts = [
        'status' => ArticleStatus::class,
    ];

    protected $attributes = [
        'summary' => 'No summary provided.',
        'priority' => 0,
        'archived_at' => null,
        'state' => 'draft',
        'status' => 'draft',
    ];
}
