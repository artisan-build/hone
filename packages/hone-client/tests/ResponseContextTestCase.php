<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class ResponseContextTestCase extends Orchestra
{
    protected function setUp(): void
    {
        Env::getRepository()->set('NIGHTWATCH_FORCE_REQUEST', 'true');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Env::getRepository()->clear('NIGHTWATCH_FORCE_REQUEST');
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            NightwatchServiceProvider::class,
            BfcClientServiceProvider::class,
            HoneClientServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('hone.url', 'https://hone.test/ingest');
        $app['config']->set('hone.token', 'secret-token');
        $app['config']->set('hone.app', 'fixture-app');
        $app['config']->set('session.driver', 'array');
    }
}
