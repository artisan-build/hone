<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Models;

use ArtisanBuild\HoneServer\Database\Factories\ActivityBucketFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ActivityBucket extends Model
{
    /** @use HasFactory<ActivityBucketFactory> */
    use HasFactory;

    protected $connection = 'hone';

    protected $guarded = [];

    protected $attributes = [
        'human_requests' => 0,
        'guest_requests' => 0,
        'guest_requests_with_queries' => 0,
        'scheduled_runs_with_queries' => 0,
        'scheduled_runs_without_queries' => 0,
        'jobs_with_queries' => 0,
        'jobs_without_queries' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_minute' => 'datetime',
            'human_requests' => 'int',
            'guest_requests' => 'int',
            'guest_requests_with_queries' => 'int',
            'scheduled_runs_with_queries' => 'int',
            'scheduled_runs_without_queries' => 'int',
            'jobs_with_queries' => 'int',
            'jobs_without_queries' => 'int',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ActivityBucketFactory::new();
    }
}
