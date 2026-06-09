<?php

declare(strict_types=1);

use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneContracts\Exceptions\InvalidEnvelope;

it('round-trips through array form', function (): void {
    $envelope = Envelope::make(
        app: 'checkout',
        deploy: 'abc1234',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select * from users', 'duration' => 12],
            ['t' => 'request', 'route' => 'GET /home', 'duration' => 88],
        ],
    );

    $restored = Envelope::fromArray($envelope->toArray());

    expect($restored->app)->toBe('checkout')
        ->and($restored->deploy)->toBe('abc1234')
        ->and($restored->sentAt)->toBe('2026-06-09T12:00:00+00:00')
        ->and($restored->envelopeVersion)->toBe(Envelope::VERSION)
        ->and($restored->records)->toHaveCount(2)
        ->and($restored->records[0]['t'])->toBe('query');
});

it('round-trips through json form', function (): void {
    $envelope = Envelope::make('billing', null, '2026-06-09T12:00:00+00:00', [
        ['t' => 'exception', 'class' => 'RuntimeException'],
    ]);

    $restored = Envelope::fromJson($envelope->toJson());

    expect($restored->app)->toBe('billing')
        ->and($restored->deploy)->toBeNull()
        ->and($restored->records[0]['t'])->toBe('exception');
});

it('stamps the current envelope version by default', function (): void {
    $envelope = Envelope::make('app', null, '2026-06-09T12:00:00+00:00', []);

    expect($envelope->envelopeVersion)->toBe(Envelope::VERSION)
        ->and($envelope->toArray()['envelope_version'])->toBe(Envelope::VERSION);
});

it('keeps inner records opaque — never interprets them', function (): void {
    $weird = ['t' => 'something_new_in_a_future_nightwatch', 'arbitrary' => ['nested' => true]];

    $envelope = Envelope::make('app', null, '2026-06-09T12:00:00+00:00', [$weird]);
    $restored = Envelope::fromArray($envelope->toArray());

    expect($restored->records[0])->toBe($weird);
});

it('tolerates unknown extra envelope keys (newer sender, older parser)', function (): void {
    $data = [
        'envelope_version' => Envelope::VERSION,
        'app' => 'app',
        'deploy' => 'sha',
        'sent_at' => '2026-06-09T12:00:00+00:00',
        'records' => [],
        'a_field_added_in_a_later_minor' => 'ignored',
        'another_future_field' => ['nested' => 1],
    ];

    $envelope = Envelope::fromArray($data);

    expect($envelope->app)->toBe('app')
        ->and($envelope->deploy)->toBe('sha');
});

it('supplies defaults for absent optional keys (older sender, newer parser)', function (): void {
    $envelope = Envelope::fromArray([
        'envelope_version' => Envelope::VERSION,
        'app' => 'app',
    ]);

    expect($envelope->deploy)->toBeNull()
        ->and($envelope->sentAt)->toBe('')
        ->and($envelope->records)->toBe([]);
});

it('peeks the version without a full parse', function (): void {
    expect(Envelope::versionFrom(['envelope_version' => 7]))->toBe(7)
        ->and(Envelope::versionFrom(['envelope_version' => '3']))->toBe(3);
});

it('rejects an envelope with no version', function (): void {
    Envelope::fromArray(['app' => 'app']);
})->throws(InvalidEnvelope::class);

it('rejects an envelope with a missing app', function (): void {
    Envelope::fromArray(['envelope_version' => Envelope::VERSION]);
})->throws(InvalidEnvelope::class);

it('rejects malformed json', function (): void {
    Envelope::fromJson('{not valid');
})->throws(InvalidEnvelope::class);

it('rejects non-array records', function (): void {
    Envelope::fromArray([
        'envelope_version' => Envelope::VERSION,
        'app' => 'app',
        'records' => 'nope',
    ]);
})->throws(InvalidEnvelope::class);
