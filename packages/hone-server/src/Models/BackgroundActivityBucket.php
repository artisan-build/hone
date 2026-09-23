<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Models;

use ArtisanBuild\HoneServer\Database\Factories\BackgroundActivityBucketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class BackgroundActivityBucket extends Model
{
    /** @use HasFactory<BackgroundActivityBucketFactory> */
    use HasFactory;

    protected $connection = 'hone';

    protected $guarded = [];

    protected $attributes = [
        'runs_with_queries' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_minute' => 'datetime',
            'runs_with_queries' => 'int',
        ];
    }

    protected static function newFactory(): BackgroundActivityBucketFactory
    {
        return BackgroundActivityBucketFactory::new();
    }
}
