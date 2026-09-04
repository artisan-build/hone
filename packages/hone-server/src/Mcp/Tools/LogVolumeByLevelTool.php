<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesCountByKeyTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('log_volume_by_level')]
#[Description('Count log records by normalized PSR log level without exposing log messages.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class LogVolumeByLevelTool extends Tool
{
    use AdvertisesToolClassification;
    use HandlesCountByKeyTool;

    protected function recordType(): string
    {
        return 'log';
    }
}
