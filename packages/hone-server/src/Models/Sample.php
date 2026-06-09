<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Models;

use ArtisanBuild\HoneServer\Database\Factories\SampleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Sample extends Model
{
    /** @use HasFactory<SampleFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $connection = 'hone';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'value' => 'float',
        ];
    }

    protected static function newFactory(): Factory
    {
        return SampleFactory::new();
    }
}
