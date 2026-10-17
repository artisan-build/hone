<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Auth;

final readonly class AuthenticatedIngestCredential
{
    public function __construct(
        public string $id,
        public string $subjectRef,
    ) {}
}
