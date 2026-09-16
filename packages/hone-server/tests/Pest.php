<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\HoneServer\Tests\TestCase;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;

uses(TestCase::class)->in(__DIR__);

function honeMcpTestSigningKey(): AsymmetricSecretKey
{
    if (app()->bound('hone.testing.mcp-signing-key')) {
        /** @var AsymmetricSecretKey */
        return app('hone.testing.mcp-signing-key');
    }

    $secret = honeMcpSigningKey();
    $keyring = new ConsoleKeyring;
    $keyring->add('hone-test-key', $secret->getPublicKey()->toHexString());
    $keyring->activate('hone-test-key');

    app()->instance('hone.testing.mcp-signing-key', $secret);

    return $secret;
}

function honeMcpSigningKey(): AsymmetricSecretKey
{
    foreach (range(1, 16) as $ignored) {
        $secret = AsymmetricSecretKey::generate(new Version4);

        if (strlen($secret->raw()) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $secret;
        }
    }

    throw new RuntimeException('Could not generate a valid PASETO signing key.');
}

/**
 * Mint the Scalpels-side assertion that Hone only verifies.
 *
 * @param  array<string, mixed>  $overrides
 */
function honeMcpAssertion(
    array $overrides = [],
    string $keyId = 'hone-test-key',
    ?AsymmetricSecretKey $secret = null,
): string {
    config()->set([
        'built-for-cloud.console.issuer' => 'https://scalpels.test',
        'built-for-cloud.console.audience' => 'https://hone.test',
    ]);

    $now = CarbonImmutable::now();
    $secret ??= honeMcpTestSigningKey();
    $claims = array_filter(array_merge([
        'iss' => 'https://scalpels.test',
        'sub' => 'operator_42',
        'aud' => 'https://hone.test',
        'iat' => $now->toAtomString(),
        'nbf' => $now->toAtomString(),
        'exp' => $now->addSeconds(90)->toAtomString(),
        'jti' => 'mint_'.bin2hex(random_bytes(8)),
        'display_name' => 'Jane Operator',
        'role' => 'admin',
        'purpose' => 'mcp',
    ], $overrides), static fn (mixed $value): bool => $value !== honeMcpAbsent());

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($secret)
        ->setClaims($claims)
        ->setFooterArray(['kid' => $keyId])
        ->toString();
}

function honeMcpAbsent(): string
{
    return '__hone_mcp_absent__';
}

/**
 * @return array<string, mixed>
 */
function honeMcpToolCall(string $name = 'list-apps-tool'): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => [],
        ],
    ];
}

/**
 * Decode the JSON payload an MCP tool returned.
 *
 * @return array<string, mixed>
 */
function honeToolPayload(TestResponse $response): array
{
    $property = new ReflectionProperty($response, 'response');

    /** @var JsonRpcResponse $jsonRpc */
    $jsonRpc = $property->getValue($response);

    /** @var array<string, mixed> */
    return json_decode((string) $jsonRpc->toArray()['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}
