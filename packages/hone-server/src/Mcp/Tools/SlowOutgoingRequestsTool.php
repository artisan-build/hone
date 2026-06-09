<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\HandlesSlowMetricTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('slow_outgoing_requests')]
#[Description('Find slow outgoing HTTP requests from daily aggregates. p95 and p99 use the worst daily percentile in the window because percentiles cannot be averaged across days.')]
#[IsReadOnly]
final class SlowOutgoingRequestsTool extends Tool
{
    use HandlesSlowMetricTool;

    protected function recordType(): string
    {
        return 'outgoing-request';
    }
}
