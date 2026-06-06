<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A UUID-keyed model: `HasUuids` makes `getKeyType()` return `string` and the route key a UUID,
 * so a `{uuidArticle}` binding should type as `string` with `format: uuid`.
 *
 * @property string $id
 */
class UuidArticle extends Model
{
    use HasUuids;

    protected $guarded = [];
}
