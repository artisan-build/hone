<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class HoneTool extends Tool
{
    final public function handle(Request $request): Response
    {
        app(ActingPrincipalResolver::class)->require(request());

        return $this->handleTelemetry($request);
    }

    abstract protected function handleTelemetry(Request $request): Response;
}
