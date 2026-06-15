<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\OpenApi\Tests\Fixtures\Models\ThrowingFactoryArticle;
use RuntimeException;

/**
 * @extends Factory<ThrowingFactoryArticle>
 */
class ThrowingArticleFactory extends Factory
{
    protected $model = ThrowingFactoryArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        throw new RuntimeException('definition() touched the database');
    }
}
