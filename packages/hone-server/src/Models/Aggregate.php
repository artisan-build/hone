<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Models;

use ArtisanBuild\HoneServer\Database\Factories\AggregateFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Aggregate extends Model
{
    /** @use HasFactory<AggregateFactory> */
    use HasFactory;

    protected $connection = 'hone';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_date' => 'date',
            'value' => 'float',
            'sample_count' => 'int',
        ];
    }

    protected static function newFactory(): Factory
    {
        return AggregateFactory::new();
    }
}
