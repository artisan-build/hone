<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database\Factories;

use ArtisanBuild\HoneServer\Models\RawEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawEvent>
 */
final class RawEventFactory extends Factory
{
    protected $model = RawEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => fake()->slug(2),
            'record_type' => 'query',
            'deploy' => fake()->optional()->sha1(),
            'occurred_at' => fake()->dateTimeBetween('-1 hour'),
            'normalized_key' => 'select-users-by-id',
            'payload' => [
                't' => 'query',
                'sql' => 'select * from users where id = ?',
                'bindings' => [fake()->numberBetween(1, 1000)],
                'duration_ms' => fake()->randomFloat(2, 1, 250),
                'connection' => ['name' => 'pgsql', 'database' => 'app'],
            ],
        ];
    }
}
