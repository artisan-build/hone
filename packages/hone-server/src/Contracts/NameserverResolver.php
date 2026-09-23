<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Contracts;

interface NameserverResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $domain): array;
}
