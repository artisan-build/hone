<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

abstract class InertByDefaultTestCase extends Orchestra
{
    /** @var resource */
    protected $nightwatchSocket;

    protected string $honeStoragePath;

    protected function setUp(): void
    {
        foreach (['HONE_URL', 'HONE_TOKEN', 'NIGHTWATCH_ENABLED', 'NIGHTWATCH_TOKEN'] as $key) {
            Env::getRepository()->clear($key);
        }

        Env::getRepository()->set('NIGHTWATCH_FORCE_REQUEST', 'true');
        $this->honeStoragePath = sys_get_temp_dir().'/hone-client-inert-'.bin2hex(random_bytes(6));

        $socket = stream_socket_server('tcp://127.0.0.1:2407', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException("Unable to listen for Nightwatch connections: {$errorMessage} [{$errorCode}]");
        }

        stream_set_blocking($socket, false);
        $this->nightwatchSocket = $socket;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        fclose($this->nightwatchSocket);
        Env::getRepository()->clear('NIGHTWATCH_FORCE_REQUEST');
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HoneClientServiceProvider::class,
            NightwatchServiceProvider::class,
            BfcClientServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->honeStoragePath);
    }
}
