<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 */
class Author extends Model
{
    protected $guarded = [];
}
