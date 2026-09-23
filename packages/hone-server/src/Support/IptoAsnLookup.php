<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Support;

use ArtisanBuild\HoneServer\Contracts\AsnLookup;
use SplFileObject;

final class IptoAsnLookup implements AsnLookup
{
    /** @var array<string, int|null> */
    private array $cache = [];

    public function __construct(private readonly ?string $path) {}

    public function lookup(?string $ipAddress): ?int
    {
        if (! $this->isPublicIp($ipAddress)) {
            return null;
        }

        /** @var string $ipAddress */
        if (array_key_exists($ipAddress, $this->cache)) {
            return $this->cache[$ipAddress];
        }

        if ($this->path === null || ! is_file($this->path) || ! is_readable($this->path)) {
            return $this->cache[$ipAddress] = null;
        }

        $target = inet_pton($ipAddress);

        if ($target === false) {
            return $this->cache[$ipAddress] = null;
        }

        $file = new SplFileObject($this->path, 'r');

        foreach ($file as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $columns = explode("\t", trim($line));

            if (count($columns) < 3) {
                continue;
            }

            $rangeStart = inet_pton($columns[0]);
            $rangeEnd = inet_pton($columns[1]);

            if ($rangeStart === false || $rangeEnd === false || strlen($rangeStart) !== strlen($target)) {
                continue;
            }

            if (strcmp($target, $rangeStart) < 0) {
                break;
            }

            if (strcmp($target, $rangeEnd) > 0) {
                continue;
            }

            $asn = (int) ltrim($columns[2], 'ASas');

            return $this->cache[$ipAddress] = $asn > 0 ? $asn : null;
        }

        return $this->cache[$ipAddress] = null;
    }

    private function isPublicIp(?string $ipAddress): bool
    {
        if ($ipAddress === null || filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        $packed = inet_pton($ipAddress);
        $sharedAddressSpace = inet_pton('100.64.0.0');

        if ($packed === false || $sharedAddressSpace === false || strlen($packed) !== strlen($sharedAddressSpace)) {
            return true;
        }

        return (ord($packed[0]) !== ord($sharedAddressSpace[0]))
            || ((ord($packed[1]) & 0xC0) !== (ord($sharedAddressSpace[1]) & 0xC0));
    }
}
