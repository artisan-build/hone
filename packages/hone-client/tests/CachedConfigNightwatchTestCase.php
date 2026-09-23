<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Tests;

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;

abstract class CachedConfigNightwatchTestCase extends InertByDefaultTestCase
{
    protected string $configurationCachePath;

    private bool $useConfigurationCache = false;

    protected function setUp(): void
    {
        $this->configurationCachePath = sys_get_temp_dir().'/hone-client-config-'.bin2hex(random_bytes(6)).'.php';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Env::getRepository()->clear('APP_CONFIG_CACHE');

        if (is_file($this->configurationCachePath)) {
            unlink($this->configurationCachePath);
        }
    }

    protected function bootWithCachedNightwatchConfiguration(): void
    {
        $configuration = config()->all();
        $configuration['hone']['url'] = null;
        $configuration['hone']['token'] = null;
        $configuration['nightwatch']['enabled'] = true;
        $configuration['nightwatch']['token'] = 'nightwatch-token';
        $configuration['nightwatch']['ingest']['uri'] = '127.0.0.1:2407';

        file_put_contents(
            $this->configurationCachePath,
            '<?php return '.var_export($configuration, true).';',
        );

        Env::getRepository()->set('APP_CONFIG_CACHE', $this->configurationCachePath);
        $this->useConfigurationCache = true;

        $this->refreshApplication();
    }

    protected function resolveApplicationResolvingCallback($app): void
    {
        parent::resolveApplicationResolvingCallback($app);

        if ($this->useConfigurationCache) {
            $app->bind(LoadConfiguration::class, LoadConfiguration::class);
        }
    }
}
