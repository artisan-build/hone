<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;

uses(WithCredentials::class);

beforeEach(function (): void {
    $this->bearer = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'hone-skill-test',
        'abilities' => [OperatorAbility::McpRead->value],
    ])->bearerHeader();
});

/**
 * Send one MCP request on the 2026-07-28 protocol revision with an MCP credential.
 *
 * @param  MakesHttpRequests&object{bearer: string}  $test
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function honeSkillRequest(object $test, string $method, array $params = []): array
{
    return $test->postJson((string) config('hone-server.mcp.path'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'hone-test', 'version' => '1.0.0'],
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
            ...$params,
        ],
    ], [
        'Authorization' => $test->bearer,
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => $method,
        ...(isset($params['uri']) ? ['Mcp-Name' => $params['uri']] : []),
    ])->assertOk()->json();
}

it('serves hone-investigate through the MCP Skills extension', function (): void {
    $file = dirname(__DIR__).'/resources/skills/hone-investigate/SKILL.md';

    $skills = honeSkillRequest($this, 'skills/list')['result']['skills'];

    expect($skills)->toHaveCount(1)
        ->and($skills[0]['uri'])->toBe('skill://hone-investigate/SKILL.md')
        ->and($skills[0]['frontmatter']['name'])->toBe('hone-investigate')
        ->and($skills[0]['resources'])->toBe([[
            'uri' => 'skill://hone-investigate/SKILL.md',
            'digest' => 'sha256:'.hash_file('sha256', $file),
            'size' => filesize($file),
        ]]);

    $contents = honeSkillRequest($this, 'resources/read', ['uri' => 'skill://hone-investigate/SKILL.md'])['result']['contents'];

    expect($contents[0]['text'])->toBe(file_get_contents($file));
});

it('names only tools and arguments the Hone server actually exposes in its skills', function (): void {
    $tools = [];
    $cursor = null;

    do {
        $page = honeSkillRequest($this, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor])['result'];
        $tools = [...$tools, ...$page['tools']];
        $cursor = $page['nextCursor'] ?? null;
    } while ($cursor !== null);

    expect($tools)->toHaveCount(count((new ReflectionProperty(HoneMcpServer::class, 'tools'))->getDefaultValue()));

    $known = collect($tools)
        ->flatMap(static fn (array $tool): array => [$tool['name'], ...array_keys($tool['inputSchema']['properties'] ?? [])])
        ->merge([
            // Scalpels relay tools and arguments, used when Hone is reached through Scalpels.
            'installed_apps', 'app_tools', 'app_read', 'app_deployment', 'intent', 'tool', 'classification_evidence', 'relay_busy',
            // Response fields and argument values the skill quotes.
            'aggregate_freshness', 'p95', 'p99',
        ])
        ->unique();

    foreach (glob(dirname(__DIR__).'/resources/skills/*/SKILL.md') ?: [] as $file) {
        preg_match_all('/`([a-z][a-z0-9_]*)(?::[^`]*)?`/', (string) file_get_contents($file), $matches);

        expect(array_values(array_diff(array_unique($matches[1]), $known->all())))
            ->toBe([], basename(dirname($file)).' names something the server does not expose');
    }
});
