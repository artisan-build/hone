<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\AppRegistry;
use Illuminate\Support\Facades\Artisan;

it('prints a plaintext token hash and registry entry that resolves through the app registry', function (): void {
    Artisan::call('hone:issue-token', ['app' => 'checkout']);

    $output = Artisan::output();

    expect($output)
        ->toContain('Plaintext token: ')
        ->toContain('SHA-256 hash: ')
        ->toContain('HONE_APP_TOKENS entry: checkout=')
        ->toContain('For multiple apps, separate entries with commas.');

    preg_match('/Plaintext token: ([a-f0-9]{64})/', $output, $tokenMatches);
    preg_match('/SHA-256 hash: ([a-f0-9]{64})/', $output, $hashMatches);
    preg_match('/HONE_APP_TOKENS entry: checkout=([a-f0-9]{64})/', $output, $entryMatches);

    $token = $tokenMatches[1] ?? '';
    $hash = $hashMatches[1] ?? '';

    expect($token)->not->toBe('')
        ->and($hash)->toBe(hash('sha256', $token))
        ->and($entryMatches[1] ?? null)->toBe($hash);

    config()->set('hone-server.apps', [
        ['id' => 'checkout', 'token_hash' => $hash],
    ]);

    expect((new AppRegistry)->resolve($token))->toBe('checkout');
});
