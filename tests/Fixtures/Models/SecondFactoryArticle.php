<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Factories\SecondFactoryArticleFactory;

/**
 * @property string $title
 * @property int    $views
 *
 * @use HasFactory<SecondFactoryArticleFactory>
 */
class SecondFactoryArticle extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'views' => 'integer',
    ];

    protected static function newFactory(): Factory
    {
        return SecondFactoryArticleFactory::new();
    }
}
