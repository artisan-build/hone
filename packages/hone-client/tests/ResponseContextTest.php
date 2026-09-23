<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * @return array<string, mixed>
 */
function capturedNightwatchRequestRecord(): array
{
    /** @var array{0: ClientRequest, 1: mixed} $exchange */
    foreach (Http::recorded() as $exchange) {
        foreach ($exchange[0]['records'] as $record) {
            if ($record['t'] === 'request') {
                return $record;
            }
        }
    }

    throw new RuntimeException('Nightwatch did not send a request record.');
}

it('captures the session cookie from outside the route middleware stack', function (): void {
    Http::fake();

    Route::middleware('web')->get('/', function (Request $request) {
        $request->session()->put('visited', true);

        return response('home')->withHeaders([
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Accept-Encoding, Cookie',
        ]);
    });

    $response = $this->get('/');
    $record = capturedNightwatchRequestRecord();
    $contextArtifact = $record['context'];
    $context = json_decode($contextArtifact, true, 512, JSON_THROW_ON_ERROR);

    $response->assertSuccessful();

    expect($response->headers->has('set-cookie'))->toBeTrue()
        ->and($context)->toBe([
            'hone.response' => [
                'sets_cookie' => true,
                'cache_control' => $response->headers->get('cache-control'),
                'vary' => $response->headers->get('vary'),
            ],
        ])
        ->and(strlen($contextArtifact))->toBeLessThan(200);
});

it('captures exact headers and no cookie from a static route', function (): void {
    Http::fake();

    Route::get('/static', fn () => response('static')->withHeaders([
        'Cache-Control' => 'public, max-age=300',
        'Vary' => 'Accept-Encoding',
    ]));

    $response = $this->get('/static');
    $record = capturedNightwatchRequestRecord();
    $contextArtifact = $record['context'];
    $context = json_decode($contextArtifact, true, 512, JSON_THROW_ON_ERROR);

    $response->assertSuccessful();

    expect($response->headers->has('set-cookie'))->toBeFalse()
        ->and($context)->toBe([
            'hone.response' => [
                'sets_cookie' => false,
                'cache_control' => $response->headers->get('cache-control'),
                'vary' => $response->headers->get('vary'),
            ],
        ])
        ->and(strlen($contextArtifact))->toBeLessThan(200);
});
