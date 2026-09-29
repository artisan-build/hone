<?php

declare(strict_types=1);

use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

final class ConditionalRegistrationServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [IneligibleTool::class];
}

#[Name('ineligible-tool')]
final class IneligibleTool extends Tool
{
    public function shouldRegister(): bool
    {
        return false;
    }

    public function handle(): Response
    {
        return Response::text('ineligible tool executed');
    }
}

it('neither lists nor calls a tool that is ineligible for registration', function (): void {
    ConditionalRegistrationServer::tools()->assertNotRegistered(IneligibleTool::class);

    ConditionalRegistrationServer::tool(IneligibleTool::class)
        ->assertHasErrors(['Tool [ineligible-tool] not found.'])
        ->assertDontSee('ineligible tool executed');
});
