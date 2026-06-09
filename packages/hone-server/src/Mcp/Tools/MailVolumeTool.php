<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesTotalVolumeTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('mail_volume')]
#[Description('Return total mail record volume over the requested aggregate window.')]
#[IsReadOnly]
final class MailVolumeTool extends Tool
{
    use HandlesTotalVolumeTool;

    protected function recordType(): string
    {
        return 'mail';
    }
}
