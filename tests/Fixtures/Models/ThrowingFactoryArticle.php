<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\OpenApi\Tests\Fixtures\Factories\ThrowingArticleFactory;

/**
 * @property string $title
 *
 * @use HasFactory<ThrowingArticleFactory>
 */
class ThrowingFactoryArticle extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return ThrowingArticleFactory::new();
    }
}
