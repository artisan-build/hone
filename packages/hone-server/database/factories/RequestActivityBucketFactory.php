<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database\Factories;

use ArtisanBuild\HoneServer\Models\RequestActivityBucket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestActivityBucket>
 */
final class RequestActivityBucketFactory extends Factory
{
    protected $model = RequestActivityBucket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => fake()->slug(2),
            'bucket_minute' => fake()->dateTimeBetween('-1 hour'),
            'actor' => 'guest',
            'path' => '/',
            'host' => 'example.com',
            'user_agent' => fake()->userAgent(),
            'asn' => fake()->numberBetween(1, 65535),
            'ran_queries' => false,
            'sets_cookie' => false,
            'cache_control' => 'public, max-age=60',
            'vary' => 'Accept-Encoding',
            'hits' => 1,
            'dimensions_hash' => md5(fake()->uuid()),
        ];
    }
}
