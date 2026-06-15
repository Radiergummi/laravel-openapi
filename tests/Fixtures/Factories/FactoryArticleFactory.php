<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\OpenApi\Tests\Fixtures\Models\FactoryArticle;

/**
 * @extends Factory<FactoryArticle>
 */
class FactoryArticleFactory extends Factory
{
    protected $model = FactoryArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Runtime fake() calls — exercise the determinism/reseed path.
            'title' => $this->faker->sentence(),
            'views' => $this->faker->numberBetween(1, 1000),
            'published' => $this->faker->boolean(),
            // Non-scalar value — must be skipped by the scalar filter.
            'tags' => $this->faker->words(3),
        ];
    }
}
