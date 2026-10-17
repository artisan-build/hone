<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Support;

final class AdvertisedIngestUrl
{
    public function resolve(): string
    {
        $ingestUrl = config('hone-server.ingest_url');

        return is_string($ingestUrl) && $ingestUrl !== ''
            ? $ingestUrl
            : route('hone-server.ingest');
    }
}
