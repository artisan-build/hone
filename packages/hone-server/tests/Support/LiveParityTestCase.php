<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Tests\Support;

use ArtisanBuild\HoneServer\Tests\TestCase;

abstract class LiveParityTestCase extends TestCase
{
    protected array $connectionsToTransact = [];

    /** The harness migrates and drops its own committed schemas, never the ordinary suite's database. */
    public function refreshDatabase(): void {}
}
