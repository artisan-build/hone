<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Models;

use ArtisanBuild\HoneServer\Database\Factories\RawEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class RawEvent extends Model
{
    /** @use HasFactory<RawEventFactory> */
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
        ];
    }

    protected static function newFactory(): Factory
    {
        return RawEventFactory::new();
    }
}
