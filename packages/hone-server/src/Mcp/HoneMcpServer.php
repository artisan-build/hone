<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp;

use ArtisanBuild\HoneServer\Mcp\Tools\DeploysTool;
use ArtisanBuild\HoneServer\Mcp\Tools\IngestFreshnessTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RecordTypesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Hone')]
#[Version('1.0.0')]
#[Instructions('Read-only telemetry for the apps reporting to this Hone server. Use these tools to answer "what should we improve this week?" - find slow or failing things and track them across deploys.')]
final class HoneMcpServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListAppsTool::class,
        RecordTypesTool::class,
        DeploysTool::class,
        IngestFreshnessTool::class,
    ];
}
