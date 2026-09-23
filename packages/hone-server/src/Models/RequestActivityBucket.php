<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Models;

use ArtisanBuild\HoneServer\Database\Factories\RequestActivityBucketFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class RequestActivityBucket extends Model
{
    /** @use HasFactory<RequestActivityBucketFactory> */
    use HasFactory;

    protected $connection = 'hone';

    protected $guarded = [];

    protected $attributes = [
        'hits' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_minute' => 'datetime',
            'ran_queries' => 'boolean',
            'sets_cookie' => 'boolean',
            'asn' => 'integer',
            'hits' => 'integer',
        ];
    }

    protected static function newFactory(): Factory
    {
        return RequestActivityBucketFactory::new();
    }
}
