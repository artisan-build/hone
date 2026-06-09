<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesCountByKeyTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('cache_stats')]
#[Description('Count cache events by store and type, such as redis:hit or redis:miss.')]
#[IsReadOnly]
final class CacheStatsTool extends Tool
{
    use HandlesCountByKeyTool;

    protected function recordType(): string
    {
        return 'cache-event';
    }
}
