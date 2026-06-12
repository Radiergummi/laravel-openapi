<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Casts\CustomObjectCast;

/**
 * Fixture for the class-form object casts (#252): the modern `casts()` style spells the JSON
 * casts as castable class-strings. The `@property` generic still disambiguates a list from a map,
 * exactly as it does for the string-form casts (#249).
 *
 * @property string               $custom
 * @property list<string>         $labels
 * @property array<string, mixed> $options
 * @property list<string>         $secrets
 * @property string               $slug
 * @property list<string>         $tags
 */
class ClassFormCastArticle extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => AsCollection::class,
            'options' => AsCollection::class,
            'labels' => AsArrayObject::class,
            'slug' => AsStringable::class,
            'secrets' => AsEncryptedCollection::class,
            'custom' => CustomObjectCast::class,
            'custom_untyped' => CustomObjectCast::class,
        ];
    }
}
