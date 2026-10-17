<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Tests\Support;

use ArtisanBuild\HoneServer\Tests\TestCase;

final class ParityApplication extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $schema = getenv('HONE_PARITY_SCHEMA');

        if (! is_string($schema) || preg_match('/^hone_parity_[a-f0-9]{24}_(php|rust)$/D', $schema) !== 1) {
            throw new \RuntimeException('A disposable parity schema is required.');
        }

        $app['config']->set([
            'database.connections.hone.search_path' => $schema,
            'queue.default' => 'sync',
            'hone-server.queue' => 'sync',
            'cache.default' => 'array',
            'logging.default' => 'null',
            'app.debug' => false,
            'hone-server.asn.iptoasn_path' => __DIR__.'/../Fixtures/ip2asn.tsv',
        ]);
    }
}
