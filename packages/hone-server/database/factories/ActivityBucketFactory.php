<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database\Factories;

use ArtisanBuild\HoneServer\Models\ActivityBucket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityBucket>
 */
final class ActivityBucketFactory extends Factory
{
    protected $model = ActivityBucket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $guestRequests = fake()->numberBetween(0, 10);

        return [
            'app' => fake()->slug(2),
            'bucket_minute' => fake()->dateTimeBetween('-7 days'),
            'human_requests' => fake()->numberBetween(0, 10),
            'guest_requests' => $guestRequests,
            'guest_requests_with_queries' => fake()->numberBetween(0, $guestRequests),
            'scheduled_runs_with_queries' => fake()->numberBetween(0, 10),
            'scheduled_runs_without_queries' => fake()->numberBetween(0, 10),
            'jobs_with_queries' => fake()->numberBetween(0, 10),
            'jobs_without_queries' => fake()->numberBetween(0, 10),
        ];
    }
}
