<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Laravel\Octane\ApplicationGateway;
use Laravel\Octane\CurrentApplication;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Octane request isolation
|--------------------------------------------------------------------------
|
| Laravel Cloud serves Hone under Octane + FrankenPHP, where one booted
| application answers many requests. These tests drive the ingest route
| through Octane's OWN per-request lifecycle — CurrentApplication::set() on a
| clone, ApplicationGateway::handle(), ApplicationGateway::terminate(), then
| flush — which is exactly the sequence Laravel\Octane\Worker::handle() runs,
| minus the FrankenPHP transport.
|
| Two properties are at stake and both are asserted here rather than reasoned
| about: the ingest controller registers its queue dispatch in an
| app()->terminating() callback, so a callback that survived a request would
| dispatch one extra job on every later request; and every request
| authenticates by bearer, so a credential resolved for one request must not
| be visible to the next.
|
*/

uses(RefreshDatabase::class, WithCredentials::class);

/**
 * Build the ingest request Octane would hand the application.
 */
function octaneIngestRequest(?string $secret, Envelope $envelope): Request
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($secret !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$secret;
    }

    return Request::create(
        uri: route('hone-server.ingest'),
        method: 'POST',
        server: $server,
        content: json_encode($envelope->toArray(), JSON_THROW_ON_ERROR),
    );
}

/**
 * Run one request through Octane's real per-request lifecycle.
 *
 * `$betweenResponseAndTerminate` runs after the application has produced the
 * response (the point at which Octane has already written it to the client)
 * but before terminating callbacks fire.
 *
 * @param  ?Closure(Response): void  $betweenResponseAndTerminate
 */
function octaneHandle(Request $request, ?Closure $betweenResponseAndTerminate = null): Response
{
    /** @var Application $app */
    $app = app();

    CurrentApplication::set($sandbox = clone $app);

    $gateway = new ApplicationGateway($app, $sandbox);

    try {
        $response = $gateway->handle($request);

        if ($betweenResponseAndTerminate instanceof Closure) {
            $betweenResponseAndTerminate($response);
        }

        $gateway->terminate($request, $response);

        return $response;
    } finally {
        $sandbox->flush();
        CurrentApplication::set($app);
    }
}

/**
 * The terminating callbacks registered on the long-lived application.
 *
 * Providers register a couple of these once at boot (Laravel's view provider
 * and laravel/mcp's client-disconnect hook). What matters under Octane is
 * that the number never GROWS: the ingest controller registers its callback
 * on the per-request sandbox, which is thrown away afterwards.
 */
function baseTerminatingCallbackCount(): int
{
    $callbacks = (new ReflectionProperty(Application::class, 'terminatingCallbacks'))
        ->getValue(app());

    return count($callbacks);
}

function octaneIngestCredential(string $subjectRef): MintedTestCredential
{
    return test()->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $subjectRef,
    ]);
}

function octaneEnvelope(string $deploy): Envelope
{
    return Envelope::make(
        app: 'reported-app-name-is-ignored',
        deploy: $deploy,
        sentAt: '2026-10-16T12:00:00+00:00',
        records: [['t' => 'query', 'sql' => 'select * from users']],
    );
}

it('dispatches the telemetry batch after the response, not during it', function (): void {
    Queue::fake();
    $credential = octaneIngestCredential('checkout');

    $response = octaneHandle(
        octaneIngestRequest($credential->plaintext(), octaneEnvelope('deploy-1')),
        function (Response $response): void {
            expect($response->getStatusCode())->toBe(202);

            // Octane has handed this response to the client by now. The batch
            // must not have been queued yet — the dispatch belongs to terminate.
            Queue::assertNothingPushed();
        },
    );

    expect($response->getStatusCode())->toBe(202);
    Queue::assertPushed(ProcessTelemetryBatch::class, 1);
});

it('dispatches exactly one job per ingest request across consecutive requests', function (): void {
    Queue::fake();
    $credential = octaneIngestCredential('checkout');

    foreach (['deploy-1', 'deploy-2', 'deploy-3'] as $deploy) {
        expect(octaneHandle(octaneIngestRequest($credential->plaintext(), octaneEnvelope($deploy)))->getStatusCode())
            ->toBe(202);
    }

    // A terminating callback that outlived its request would make request two
    // dispatch twice and request three dispatch three times: six, not three.
    Queue::assertPushed(ProcessTelemetryBatch::class, 3);

    expect(collect(Queue::pushed(ProcessTelemetryBatch::class))->map(fn (ProcessTelemetryBatch $job): ?string => $job->deploy)->all())
        ->toBe(['deploy-1', 'deploy-2', 'deploy-3']);
});

it('accumulates no terminating callbacks on the long-lived application', function (): void {
    Queue::fake();
    $credential = octaneIngestCredential('checkout');

    $atBoot = baseTerminatingCallbackCount();

    foreach (['deploy-1', 'deploy-2', 'deploy-3'] as $deploy) {
        octaneHandle(octaneIngestRequest($credential->plaintext(), octaneEnvelope($deploy)));

        expect(baseTerminatingCallbackCount())->toBe($atBoot);
    }
});

it('does not carry an authenticated identity into a request that presents no credential', function (): void {
    Queue::fake();
    $credential = octaneIngestCredential('checkout');

    expect(octaneHandle(octaneIngestRequest($credential->plaintext(), octaneEnvelope('deploy-1')))->getStatusCode())
        ->toBe(202);

    $unauthenticated = octaneHandle(octaneIngestRequest(null, octaneEnvelope('deploy-2')));

    expect($unauthenticated->getStatusCode())->toBe(401);
    Queue::assertPushed(ProcessTelemetryBatch::class, 1);
    expect(collect(Queue::pushed(ProcessTelemetryBatch::class))->map(fn (ProcessTelemetryBatch $job): string => $job->app)->all())
        ->toBe(['checkout']);
});

it('attributes each request to the credential that request presented', function (): void {
    Queue::fake();
    $first = octaneIngestCredential('source-app-a');
    $second = octaneIngestCredential('source-app-b');

    expect(octaneHandle(octaneIngestRequest($first->plaintext(), octaneEnvelope('deploy-a')))->getStatusCode())->toBe(202);
    expect(octaneHandle(octaneIngestRequest($second->plaintext(), octaneEnvelope('deploy-b')))->getStatusCode())->toBe(202);

    expect(collect(Queue::pushed(ProcessTelemetryBatch::class))->map(fn (ProcessTelemetryBatch $job): string => $job->app)->all())
        ->toBe(['source-app-a', 'source-app-b']);

    // And in the other order, against fresh sandboxes, so neither result is an
    // artefact of which credential happened to be resolved first.
    expect(octaneHandle(octaneIngestRequest($second->plaintext(), octaneEnvelope('deploy-c')))->getStatusCode())->toBe(202);
    expect(octaneHandle(octaneIngestRequest($first->plaintext(), octaneEnvelope('deploy-d')))->getStatusCode())->toBe(202);

    expect(collect(Queue::pushed(ProcessTelemetryBatch::class))->map(fn (ProcessTelemetryBatch $job): string => $job->app)->all())
        ->toBe(['source-app-a', 'source-app-b', 'source-app-b', 'source-app-a']);
});

it('rejects an unknown bearer token presented after a valid one', function (): void {
    Queue::fake();
    $credential = octaneIngestCredential('checkout');

    expect(octaneHandle(octaneIngestRequest($credential->plaintext(), octaneEnvelope('deploy-1')))->getStatusCode())->toBe(202);
    expect(octaneHandle(octaneIngestRequest('not-a-real-token', octaneEnvelope('deploy-2')))->getStatusCode())->toBe(401);

    Queue::assertPushed(ProcessTelemetryBatch::class, 1);
});
