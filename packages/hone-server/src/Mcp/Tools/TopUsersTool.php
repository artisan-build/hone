<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesCountByKeyTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('top_users')]
#[Description('Count user records by user id only. Names, usernames, and emails are never used as keys.')]
#[IsReadOnly]
final class TopUsersTool extends Tool
{
    use HandlesCountByKeyTool;

    protected function recordType(): string
    {
        return 'user';
    }
}
