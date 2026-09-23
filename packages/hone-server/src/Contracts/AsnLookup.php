<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Contracts;

interface AsnLookup
{
    public function lookup(?string $ipAddress): ?int;
}
