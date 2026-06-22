<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Enums\ArticleStatus;

/**
 * Maps onto the `widgets` fixture migration. `price` is cast to `decimal:2` while the migration
 * declares `decimal('price', 8, 2)`, so cast-vs-migration precedence is observable; the other
 * fillable columns are left uncast so the migration is their only source of field metadata.
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
