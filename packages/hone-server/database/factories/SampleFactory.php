<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database\Factories;

use ArtisanBuild\HoneServer\Models\Sample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sample>
 */
final class SampleFactory extends Factory
{
    protected $model = Sample::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => fake()->slug(2),
            'record_type' => 'request',
            'normalized_key' => 'GET /dashboard',
            'deploy' => fake()->optional()->sha1(),
            'occurred_at' => fake()->dateTimeBetween('-1 hour'),
            'payload' => [
                't' => 'request',
                'method' => 'GET',
                'uri' => '/dashboard',
                'status' => 200,
                'context' => ['route' => 'dashboard', 'middleware' => ['web', 'auth']],
            ],
            'value' => fake()->optional()->randomFloat(2, 1, 1000),
        ];
    }
}
