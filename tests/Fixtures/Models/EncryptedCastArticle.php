<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for parameterised encrypted casts (#283): encrypted:array, encrypted:collection,
 * encrypted:object, and encrypted:json must map to the same schema as their non-encrypted
 * counterparts, while bare encrypted stays a string.
 *
 * @property list<string>         $labels
 * @property array<string, mixed> $options
 * @property list<string>         $tags
 */
class EncryptedCastArticle extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'encrypted:array',
        'preferences' => 'encrypted:collection',
        'payload' => 'encrypted:object',
        'data' => 'encrypted:json',
        'secret' => 'encrypted',
        'tags' => 'encrypted:array',
        'labels' => 'encrypted:collection',
        'options' => 'encrypted:array',
    ];
}
