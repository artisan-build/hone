<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesCountByKeyTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('scheduled_task_health')]
#[Description('Return scheduled task counts and duration aggregates by task name.')]
#[IsReadOnly]
final class ScheduledTaskHealthTool extends Tool
{
    use HandlesCountByKeyTool;

    protected function recordType(): string
    {
        return 'scheduled-task';
    }
}
