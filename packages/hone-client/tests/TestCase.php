<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Tests;

use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HoneClientServiceProvider::class];
    }
}
