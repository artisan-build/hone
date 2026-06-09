<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesCountByKeyTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('command_stats')]
#[Description('Return command counts and duration aggregates by command name.')]
#[IsReadOnly]
final class CommandStatsTool extends Tool
{
    use HandlesCountByKeyTool;

    protected function recordType(): string
    {
        return 'command';
    }
}
