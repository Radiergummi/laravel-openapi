<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\OpenApi\Tests\Fixtures\Models\SecondFactoryArticle;

/**
 * A factory with the same definition shape as {@see FactoryArticleFactory}, on a different model.
 * Used to prove the per-model seed mixing draws distinct values for distinct model classes.
 *
 * @extends Factory<SecondFactoryArticle>
 */
class SecondFactoryArticleFactory extends Factory
{
    protected $model = SecondFactoryArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(),
            'views' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
