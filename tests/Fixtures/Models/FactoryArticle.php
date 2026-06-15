<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Factories\FactoryArticleFactory;

/**
 * @property bool         $published
 * @property list<string> $tags
 * @property string       $title
 * @property int          $views
 *
 * @use HasFactory<FactoryArticleFactory>
 */
class FactoryArticle extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'views' => 'integer',
        'published' => 'boolean',
        'tags' => 'array',
    ];

    protected static function newFactory(): Factory
    {
        return FactoryArticleFactory::new();
    }
}
