<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer;

final class AppRegistry
{
    public function resolve(string $bearerToken): ?string
    {
        if ($bearerToken === '') {
            return null;
        }

        $actualHash = hash('sha256', $bearerToken);

        foreach ($this->apps() as $app) {
            if (hash_equals($app['token_hash'], $actualHash)) {
                return $app['id'];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, token_hash: string}>
     */
    private function apps(): array
    {
        $apps = config('hone-server.apps', []);

        if (! is_array($apps)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $app): ?array {
            if (! is_array($app)) {
                return null;
            }

            $id = $app['id'] ?? null;
            $tokenHash = $app['token_hash'] ?? null;

            if (! is_string($id) || $id === '' || ! is_string($tokenHash) || $tokenHash === '') {
                return null;
            }

            return ['id' => $id, 'token_hash' => $tokenHash];
        }, $apps)));
    }
}
