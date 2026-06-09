<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Http\Controllers;

use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneContracts\Exceptions\InvalidEnvelope;
use ArtisanBuild\HoneServer\AppRegistry;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use JsonException;

final class IngestController
{
    public function ingest(Request $request, AppRegistry $registry): JsonResponse
    {
        $appId = $registry->resolve((string) $request->bearerToken());

        if ($appId === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return response()->json(['message' => 'Envelope is not valid JSON: '.$e->getMessage()], 422);
        }

        if (! is_array($data)) {
            return response()->json(['message' => 'Envelope JSON must decode to an object.'], 422);
        }

        try {
            /** @var array<string, mixed> $data */
            $version = Envelope::versionFrom($data);
        } catch (InvalidEnvelope $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($version > Envelope::VERSION) {
            return response()->json([
                'message' => "Envelope v{$version} is newer than this Hone server (max v".Envelope::VERSION.'). Upgrade your Hone app.',
            ], 422);
        }

        try {
            $envelope = Envelope::fromArray($data);
        } catch (InvalidEnvelope $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $job = new ProcessTelemetryBatch(
            app: $appId,
            deploy: $envelope->deploy,
            sentAt: $envelope->sentAt,
            records: $envelope->records,
        );

        $queueConnection = config('hone-server.queue');

        if (is_string($queueConnection) && $queueConnection !== '') {
            $job->connection = $queueConnection;
        }

        app()->terminating(function () use ($job): void {
            Bus::dispatch($job);
        });

        return response()->json(['message' => 'Accepted.'], 202);
    }

    public function capabilities(): JsonResponse
    {
        return response()->json([
            'envelope' => [
                'min_major' => 1,
                'max_major' => Envelope::VERSION,
                'supported_majors' => range(1, Envelope::VERSION),
            ],
        ]);
    }
}
