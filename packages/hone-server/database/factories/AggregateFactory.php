<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database\Factories;

use ArtisanBuild\HoneServer\Models\Aggregate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Aggregate>
 */
final class AggregateFactory extends Factory
{
    protected $model = Aggregate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => fake()->slug(2),
            'record_type' => 'query',
            'normalized_key' => 'select-users-by-id',
            'deploy' => fake()->optional()->sha1(),
            'bucket_date' => fake()->dateTimeBetween('-7 days'),
            'metric' => 'duration_p95_ms',
            'value' => fake()->randomFloat(2, 1, 500),
            'sample_count' => fake()->numberBetween(1, 1000),
        ];
    }
}
