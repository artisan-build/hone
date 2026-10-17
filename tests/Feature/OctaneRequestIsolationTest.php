<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;
use Laravel\Octane\ApplicationGateway;
use Laravel\Octane\CurrentApplication;
use Symfony\Component\HttpFoundation\Response;

final class OctaneMcpIsolationProbe
{
    /**
     * @var list<array{
     *     credential_id: string,
     *     subject_ref: string,
     *     abilities: list<string>,
     *     effect_ceiling: string|null,
     *     tool_instance_invocations: int
     * }>
     */
    public array $executions = [];

    /** @var list<array{before: int, after: int}> */
    public array $serverStates = [];

    public function __construct(public readonly ?int $serverStateMutationDelta = null) {}
}

#[Name('octane-request-isolation-probe')]
#[ToolEffect(Effect::Read)]
final class OctaneMcpIsolationProbeTool extends Tool
{
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    private int $invocations = 0;

    public function __construct(
        private readonly OctaneMcpIsolationProbe $probe,
        private readonly HoneMcpServer $server,
    ) {}

    public function handle(Request $request): McpResponse
    {
        $credential = $request->user();

        if (! $credential instanceof Credential) {
            throw new LogicException('The MCP isolation probe requires a credential principal.');
        }

        if ($this->probe->serverStateMutationDelta !== null) {
            $before = $this->server->maxPaginationLength;
            $this->server->maxPaginationLength += $this->probe->serverStateMutationDelta;
            $this->probe->serverStates[] = [
                'before' => $before,
                'after' => $this->server->maxPaginationLength,
            ];
        }

        $execution = [
            'credential_id' => (string) $credential->getAuthIdentifier(),
            'subject_ref' => $credential->subject_ref,
            'abilities' => array_values($credential->abilities ?? []),
            'effect_ceiling' => RequestEffectCeiling::current($request)?->value,
            'tool_instance_invocations' => ++$this->invocations,
        ];

        $this->probe->executions[] = $execution;

        return McpResponse::json($execution);
    }
}

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

function octaneMcpRequest(?string $secret, int $id): Request
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($secret !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$secret;
    }

    return Request::create(
        uri: (string) config('hone-server.mcp.path'),
        method: 'POST',
        server: $server,
        content: json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => [
                'name' => 'octane-request-isolation-probe',
                'arguments' => [],
            ],
        ], JSON_THROW_ON_ERROR),
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

/** @param list<string> $abilities */
function octaneMcpCredential(string $subjectRef, array $abilities): MintedTestCredential
{
    return test()->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $subjectRef,
        'abilities' => $abilities,
    ]);
}

function registerOctaneMcpIsolationProbe(OctaneMcpIsolationProbe $probe): void
{
    app()->instance(OctaneMcpIsolationProbe::class, $probe);
    app()->singleton(OctaneMcpIsolationProbeTool::class);
    app()->bind(static function (Application $application, array $parameters): HoneMcpServer {
        $transport = $parameters['transport'] ?? null;

        if (! $transport instanceof Transport) {
            throw new LogicException('The MCP isolation server requires an HTTP transport.');
        }

        $server = new HoneMcpServer($transport);
        $application->instance(HoneMcpServer::class, $server);
        $tools = new ReflectionProperty($server, 'tools')->getValue($server);

        if (! is_array($tools)) {
            throw new LogicException('The Hone MCP tool registry must be an array.');
        }

        new ReflectionProperty($server, 'tools')->setValue($server, [
            ...$tools,
            OctaneMcpIsolationProbeTool::class,
        ]);

        return $server;
    });
}

/**
 * @return array{
 *     credential_id: string,
 *     subject_ref: string,
 *     abilities: list<string>,
 *     effect_ceiling: string|null,
 *     tool_instance_invocations: int
 * }
 */
function octaneMcpProbeResult(Response $response): array
{
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $text = $payload['result']['content'][0]['text'] ?? null;

    if (! is_string($text)) {
        throw new LogicException('The MCP isolation probe did not return text content.');
    }

    /** @var array{
     *     credential_id: string,
     *     subject_ref: string,
     *     abilities: list<string>,
     *     effect_ceiling: string|null,
     *     tool_instance_invocations: int
     * }
     */
    return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
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

it('isolates MCP credential identity and scope across consecutive requests', function (): void {
    $probe = new OctaneMcpIsolationProbe;
    registerOctaneMcpIsolationProbe($probe);
    $first = octaneMcpCredential('mcp-source-a', [
        OperatorAbility::McpRead->value,
        OperatorAbility::AuditRead->value,
    ]);
    $second = octaneMcpCredential('mcp-source-b', [
        OperatorAbility::McpRead->value,
    ]);

    $firstResponse = octaneHandle(octaneMcpRequest($first->plaintext(), 1));
    $secondResponse = octaneHandle(octaneMcpRequest($second->plaintext(), 2));

    expect($firstResponse->getStatusCode())->toBe(200)
        ->and($secondResponse->getStatusCode())->toBe(200)
        ->and(octaneMcpProbeResult($firstResponse))->toBe([
            'credential_id' => $first->credential->id,
            'subject_ref' => 'mcp-source-a',
            'abilities' => [OperatorAbility::McpRead->value, OperatorAbility::AuditRead->value],
            'effect_ceiling' => Effect::Read->value,
            'tool_instance_invocations' => 1,
        ])
        ->and(octaneMcpProbeResult($secondResponse))->toBe([
            'credential_id' => $second->credential->id,
            'subject_ref' => 'mcp-source-b',
            'abilities' => [OperatorAbility::McpRead->value],
            'effect_ceiling' => Effect::Read->value,
            'tool_instance_invocations' => 1,
        ])
        ->and($probe->executions)->toHaveCount(2);
});

it('does not execute an MCP tool when an unauthenticated request follows an authenticated one', function (): void {
    $probe = new OctaneMcpIsolationProbe;
    registerOctaneMcpIsolationProbe($probe);
    $credential = octaneMcpCredential('authenticated-mcp-source', [OperatorAbility::McpRead->value]);

    expect(octaneHandle(octaneMcpRequest($credential->plaintext(), 1))->getStatusCode())->toBe(200)
        ->and($probe->executions)->toHaveCount(1);

    $unauthenticated = octaneHandle(octaneMcpRequest(null, 1));

    expect($unauthenticated->getStatusCode())->toBe(401)
        ->and($probe->executions)->toHaveCount(1)
        ->and($probe->executions[0]['subject_ref'])->toBe('authenticated-mcp-source');
});

it('starts mutable MCP tool and server state clean in each Octane sandbox', function (): void {
    $probe = new OctaneMcpIsolationProbe(serverStateMutationDelta: 37);
    registerOctaneMcpIsolationProbe($probe);
    $credential = octaneMcpCredential('state-isolation-source', [OperatorAbility::McpRead->value]);

    $first = octaneHandle(octaneMcpRequest($credential->plaintext(), 1));
    $second = octaneHandle(octaneMcpRequest($credential->plaintext(), 2));

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(200)
        ->and(octaneMcpProbeResult($first)['tool_instance_invocations'])->toBe(1)
        ->and(octaneMcpProbeResult($second)['tool_instance_invocations'])->toBe(1)
        ->and(array_column($probe->executions, 'tool_instance_invocations'))->toBe([1, 1])
        ->and($probe->serverStates)->toHaveCount(2)
        ->and($probe->serverStates[0]['after'])->toBe($probe->serverStates[0]['before'] + 37)
        ->and($probe->serverStates[1]['before'])->toBe($probe->serverStates[0]['before'])
        ->and($probe->serverStates[1]['before'])->not->toBe($probe->serverStates[0]['after'])
        ->and($probe->serverStates[1]['after'])->toBe($probe->serverStates[1]['before'] + 37);
});
