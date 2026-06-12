<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fixture for the array/json/collection cast disambiguation (#249): the docblock
 * generic decides whether a JSON column documents as a list or an object.
 *
 * @property array<int, string>   $aliases
 * @property string[]             $flags
 * @property ?list<string>        $maybe_tags
 * @property list<Carbon>         $milestones
 * @property array<string, mixed> $options
 * @property list<int>            $ranks
 * @property list<string>         $settings
 * @property list<string>         $tags
 */
class JsonColumnArticle extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'aliases' => 'array',
        'tags' => 'array',
        'flags' => 'json',
        'ranks' => 'collection',
        'options' => 'array',
        'meta' => 'array',
        'maybe_tags' => 'array',
        'milestones' => 'array',
        'settings' => 'object',
    ];
}
