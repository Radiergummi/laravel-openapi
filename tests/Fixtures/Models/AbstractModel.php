<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An abstract model — instantiating it via `new` throws an `Error`, which the resolver fault
 * boundary deliberately does not catch. {@see EloquentModelToSchema} must degrade gracefully
 * rather than let that Error abort the whole generation run.
 *
 * @property string $id
 */
abstract class AbstractModel extends Model
{
    protected $guarded = [];
}
