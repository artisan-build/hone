<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Support\IptoAsnLookup;

it('keeps the committed Rust row fixture equal to the real PHP persistence path', function (): void {
    $path = __DIR__.'/Fixtures/rust-ingest-rows.json';
    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($fixture)->toBeArray();

    (new ProcessTelemetryBatch(
        app: $fixture['app'],
        deploy: $fixture['deploy'],
        sentAt: $fixture['sent_at'],
        records: $fixture['records'],
    ))->handle(new IptoAsnLookup(__DIR__.'/Fixtures/ip2asn.tsv'));

    $actual = RawEvent::query()
        ->orderBy('id')
        ->get()
        ->map(static fn (RawEvent $event): array => [
            'app' => $event->app,
            'record_type' => $event->record_type,
            'deploy' => $event->deploy,
            'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'normalized_key' => $event->normalized_key,
            'duration_ms' => $event->duration_ms,
            'payload' => $event->payload,
            'actor' => $event->actor,
            'ran_queries' => $event->ran_queries,
            'response' => $event->response,
            'request_path' => $event->request_path,
            'request_host' => $event->request_host,
            'user_agent' => $event->user_agent,
            'client_ip' => $event->client_ip,
            'asn' => $event->asn,
        ])
        ->all();

    if (env('HONE_UPDATE_RUST_INGEST_FIXTURE') === '1') {
        $fixture['expected'] = $actual;
        file_put_contents(
            $path,
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n",
        );

        $this->fail('The Rust ingest fixture was regenerated. Review and commit it, then rerun without HONE_UPDATE_RUST_INGEST_FIXTURE.');
    }

    expect($actual)->toBe($fixture['expected']);
});
