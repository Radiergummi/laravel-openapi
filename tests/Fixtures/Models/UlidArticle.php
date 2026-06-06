<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A ULID-keyed model: `HasUlids` makes `getKeyType()` return `string`, but there is no standard
 * OpenAPI `format` for ULID, so a `{ulidArticle}` binding types as a bare `string`.
 *
 * @property string $id
 */
class UlidArticle extends Model
{
    use HasUlids;

    protected $guarded = [];
}
