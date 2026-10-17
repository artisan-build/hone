<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Tests\Support\LiveIngestHarness;
use ArtisanBuild\HoneServer\Tests\Support\ReceivePathCorpus;
use Illuminate\Support\Facades\DB;

it('persists the shared receive corpus identically through both live HTTP implementations', function (): void {
    if (LiveIngestHarness::cargo() === null) {
        $this->markTestSkipped('Live Rust ingest parity requires Cargo/Rust; the dedicated CI parity job installs it and fails on skips.');
    }

    $harness = new LiveIngestHarness;
    expect(DB::connection('hone')->transactionLevel())->toBe(0);

    try {
        $harness->start();
        $harness->assertAuthentication();

        foreach (ReceivePathCorpus::cases() as $name => $case) {
            $harness->assertCase($name, $case);
        }
    } finally {
        $harness->stop();
    }
})->group('rust-ingest-parity');
