<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Support;

use ArtisanBuild\HoneServer\Contracts\NameserverResolver;

final class DnsNameserverResolver implements NameserverResolver
{
    public function resolve(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS);

        if (! is_array($records)) {
            return [];
        }

        $nameservers = [];

        foreach ($records as $record) {
            $target = $record['target'] ?? null;

            if (is_string($target) && $target !== '') {
                $nameservers[] = strtolower(rtrim($target, '.'));
            }
        }

        $nameservers = array_values(array_unique($nameservers));
        sort($nameservers);

        return $nameservers;
    }
}
