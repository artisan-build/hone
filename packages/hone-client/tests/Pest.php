<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\Tests\CachedConfigNightwatchTestCase;
use ArtisanBuild\HoneClient\Tests\InertByDefaultTestCase;
use ArtisanBuild\HoneClient\Tests\ResponseContextTestCase;
use ArtisanBuild\HoneClient\Tests\TestCase;

uses(TestCase::class)->in('HoneClientTest.php');
uses(CachedConfigNightwatchTestCase::class)->in('CachedConfigNightwatchTest.php');
uses(InertByDefaultTestCase::class)->in('InertByDefaultTest.php');
uses(ResponseContextTestCase::class)->in('ResponseContextTest.php');
