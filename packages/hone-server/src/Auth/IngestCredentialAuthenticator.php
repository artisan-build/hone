<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Auth;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialUsageRecorder;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use SensitiveParameter;

final readonly class IngestCredentialAuthenticator
{
    private const CACHE_KEY_PREFIX = 'hone-server:ingest-credential:';

    public function __construct(
        private BearerAuthenticator $authenticator,
        private AppPurposeRegistry $purposes,
        private CredentialUsageRecorder $usage,
        private Repository $cache,
    ) {}

    public function authenticate(Request $request): ?AuthenticatedIngestCredential
    {
        $ttl = (int) config('hone-server.ingest.credential_cache_ttl', 60);
        $secret = $request->bearerToken();

        if ($ttl <= 0 || $secret === null || $secret === '') {
            return $this->resolve($request);
        }

        $key = $this->cacheKey($secret);
        $cached = $this->fromCache($this->cache->get($key));

        if ($cached !== null) {
            return $cached;
        }

        $credential = $this->authenticateCredential($request);

        if ($credential === null) {
            return null;
        }

        $resolved = $this->toResolvedCredential($credential);
        $expiresAt = now()->addSeconds($ttl);

        if ($credential->expires_at !== null && $credential->expires_at->lt($expiresAt)) {
            $expiresAt = $credential->expires_at;
        }

        $this->cache->put($key, [
            'credential_id' => $resolved->id,
            'subject_ref' => $resolved->subjectRef,
        ], $expiresAt);

        return $resolved;
    }

    private function resolve(Request $request): ?AuthenticatedIngestCredential
    {
        $credential = $this->authenticateCredential($request);

        return $credential === null ? null : $this->toResolvedCredential($credential);
    }

    private function authenticateCredential(Request $request): ?Credential
    {
        $credential = $this->authenticator->credential($request);

        if ($credential === null
            || $credential->purpose !== $this->purposes->purpose('hone.ingest')
            || $credential->subject_type !== SubjectType::Installation
            || $credential->ownership() !== CredentialOwnership::Installation
            || ! $this->usage->recordUsage($credential)) {
            return null;
        }

        return $credential;
    }

    private function toResolvedCredential(Credential $credential): AuthenticatedIngestCredential
    {
        return new AuthenticatedIngestCredential(
            id: $credential->id,
            subjectRef: $credential->subject_ref,
        );
    }

    private function fromCache(mixed $value): ?AuthenticatedIngestCredential
    {
        if (! is_array($value)
            || ! is_string($value['credential_id'] ?? null)
            || ! is_string($value['subject_ref'] ?? null)) {
            return null;
        }

        return new AuthenticatedIngestCredential(
            id: $value['credential_id'],
            subjectRef: $value['subject_ref'],
        );
    }

    private function cacheKey(#[SensitiveParameter] string $secret): string
    {
        return self::CACHE_KEY_PREFIX.hash('sha256', $secret);
    }
}
