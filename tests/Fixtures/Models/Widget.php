<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;

/**
 * Maps onto the `widgets` fixture migration. A `name` cast deliberately collides with the
 * migration's `string('name', 120)` column so cast-vs-migration precedence is observable.
 *
 * @property string $price
 */
class Widget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'configuration' => 'array',
        'status' => ArticleStatus::class,
    ];

    protected $fillable = [
        'id',
        'last_ip',
        'price',
        'name',
        'quantity',
        'configuration',
        'size',
        'status',
        'label',
        'notes',
    ];
}
