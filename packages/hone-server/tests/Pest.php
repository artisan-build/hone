<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\HoneServer\Tests\TestCase;
use Carbon\CarbonImmutable;
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

    foreach (range(1, 16) as $ignored) {
        $secret = AsymmetricSecretKey::generate(new Version4);

        if (strlen($secret->raw()) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $keyring = new ConsoleKeyring;
            $keyring->add('hone-test-key', $secret->getPublicKey()->toHexString());
            $keyring->activate('hone-test-key');

            app()->instance('hone.testing.mcp-signing-key', $secret);

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
function honeMcpAssertion(array $overrides = []): string
{
    config()->set([
        'built-for-cloud.console.issuer' => 'https://scalpels.test',
        'built-for-cloud.console.audience' => 'https://hone.test',
    ]);

    $now = CarbonImmutable::now();
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
        ->setKey(honeMcpTestSigningKey())
        ->setClaims($claims)
        ->setFooterArray(['kid' => 'hone-test-key'])
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
