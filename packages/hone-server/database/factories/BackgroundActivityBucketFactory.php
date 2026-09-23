<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database\Factories;

use ArtisanBuild\HoneServer\Models\BackgroundActivityBucket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackgroundActivityBucket>
 */
final class BackgroundActivityBucketFactory extends Factory
{
    protected $model = BackgroundActivityBucket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => fake()->slug(2),
            'bucket_minute' => fake()->dateTimeBetween('-7 days'),
            'activity_type' => fake()->randomElement(['scheduled', 'job']),
            'identity' => fake()->word(),
            'runs_with_queries' => fake()->numberBetween(1, 10),
        ];
    }
}
